<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NodeSyncService;
use App\Services\NoBrand\NoBrandDriver;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    /**
     * 获取机器列表（附带关联节点数）
     */
    public function fetch(Request $request)
    {
        $machines = ServerMachine::withCount('servers')
            ->orderBy('id')
            ->get()
            ->map(function (ServerMachine $machine) {
                return [
                    'id' => $machine->id,
                    'name' => $machine->name,
                    'notes' => $machine->notes,
                    'is_active' => $machine->is_active,
                    'agent_driver' => $machine->agent_driver ?: 'xboard-node',
                    'agent_settings' => $machine->agent_settings,
                    'nobrand_status' => $machine->nobrand_status,
                    'nobrand_last_seen_at' => $machine->nobrand_last_seen_at,
                    'last_seen_at' => $machine->last_seen_at,
                    'load_status' => $machine->load_status,
                    'servers_count' => $machine->servers_count,
                    'created_at' => $machine->created_at,
                    'updated_at' => $machine->updated_at,
                ];
            });

        return $this->success($machines);
    }

    /**
     * 创建 / 更新机器
     */
    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_server_machine,id',
            'name' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'agent_driver' => 'nullable|string|in:xboard-node,nobrand-hybrid',
            'agent_settings' => 'nullable|array',
        ]);

        if (!empty($params['id'])) {
            $machine = ServerMachine::find($params['id']);
            $update = ['name' => $params['name']];
            if (array_key_exists('notes', $params)) {
                $update['notes'] = $params['notes'];
            }
            if (array_key_exists('is_active', $params)) {
                $update['is_active'] = $params['is_active'];
            }
            if (array_key_exists('agent_driver', $params)) {
                $update['agent_driver'] = $params['agent_driver'];
            }
            if (array_key_exists('agent_settings', $params)) {
                $update['agent_settings'] = $params['agent_settings'];
            }

            if (($update['agent_driver'] ?? $machine->agent_driver) !== 'nobrand-hybrid'
                && $machine->servers()->where('runtime_driver', 'nobrand')->exists()) {
                return $this->fail([422, '该机器仍绑定 NoBrand Runtime 节点，不能切回普通 Agent']);
            }

            $machine->update($update);
            return $this->success(true);
        }

        $machine = ServerMachine::create([
            'name' => $params['name'],
            'notes' => $params['notes'] ?? null,
            'is_active' => $params['is_active'] ?? true,
            'agent_driver' => $params['agent_driver'] ?? 'xboard-node',
            'agent_settings' => $params['agent_settings'] ?? null,
            'token' => ServerMachine::generateToken(),
        ]);

        return $this->success([
            'id' => $machine->id,
            'token' => $machine->token,
            'install_command' => $this->buildInstallCommand($request, $machine),
        ]);
    }

    /**
     * 重置机器 Token
     */
    public function resetToken(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);
        $token = ServerMachine::generateToken();
        $machine->update(['token' => $token]);

        return $this->success(['token' => $token]);
    }

    /**
     * 获取机器 Token（仅展示一次，用于首次配置）
     */
    public function getToken(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);

        return $this->success(['token' => $machine->token]);
    }

    /**
     * 获取机器模式一键安装命令
     */
    public function installCommand(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);

        return $this->success([
            'command' => $this->buildInstallCommand($request, $machine),
        ]);
    }

    /**
     * 删除机器（自动解除关联节点）
     */
    public function drop(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);
        $machineId = $machine->id;

        // Detach nodes first (sets machine_id = null), then delete and notify
        Server::where('machine_id', $machineId)->update(['machine_id' => null]);
        $machine->delete();

        // Notify with empty node list so WS process cleans up registry
        NodeSyncService::notifyMachineNodesChanged($machineId);

        return $this->success(true);
    }

    public function nobrandCapabilities()
    {
        return $this->success(NoBrandDriver::capabilities());
    }

    /**
     * 获取机器下的节点列表
     */
    public function nodes(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $nodes = Server::where('machine_id', $params['machine_id'])
            ->orderBy('sort')
            ->get(['id', 'name', 'type', 'host', 'port', 'show', 'enabled', 'sort']);

        return $this->success($nodes);
    }

    /**
     * 获取机器负载历史
     */
    public function history(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'limit' => 'nullable|integer|min:10|max:1440',
            'range_hours' => 'nullable|integer|min:1|max:24',
        ]);

        $query = ServerMachineLoadHistory::query()
            ->where('machine_id', $params['machine_id']);

        if (!empty($params['range_hours'])) {
            $query->where('recorded_at', '>=', now()->subHours((int) $params['range_hours'])->timestamp);
        }

        $limit = (int) ($params['limit'] ?? 60);

        $history = $query
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get([
                'cpu',
                'mem_total',
                'mem_used',
                'disk_total',
                'disk_used',
                'net_in_speed',
                'net_out_speed',
                'recorded_at',
            ])
            ->reverse()
            ->values();

        return $this->success($history);
    }

    private function buildInstallCommand(Request $request, ServerMachine $machine): string
    {
        $panelUrl = rtrim((string) (admin_setting('app_url') ?: $request->getSchemeAndHttpHost()), '/');
        $installerUrl = 'https://raw.githubusercontent.com/cedar2025/xboard-node/dev/install.sh';

        $xboardCommand = sprintf(
            'curl -fsSL %s | sudo bash -s -- --mode machine --panel %s --token %s --machine-id %d',
            $installerUrl,
            escapeshellarg($panelUrl),
            escapeshellarg($machine->token),
            $machine->id
        );

        if (($machine->agent_driver ?: 'xboard-node') !== NoBrandDriver::DRIVER) {
            return $xboardCommand;
        }

        return $xboardCommand
            . ' && ' . NoBrandDriver::managerBootstrapCommand()
            . ' && ' . NoBrandDriver::companionBootstrapCommand(
                $panelUrl,
                (int) $machine->id,
                (string) $machine->token
            );
    }
}
