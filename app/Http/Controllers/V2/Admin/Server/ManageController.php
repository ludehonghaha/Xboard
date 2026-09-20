<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerMachine;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    private function validateRuntimeDriverBinding(array $params, ?Server $existing = null): ?array
    {
        $runtimeDriver = $params['runtime_driver'] ?? $existing?->runtime_driver ?? 'native';
        $machineId = array_key_exists('machine_id', $params)
            ? ($params['machine_id'] ?: null)
            : $existing?->machine_id;

        if ($runtimeDriver !== 'nobrand') {
            return null;
        }

        $type = $params['type'] ?? $existing?->type;
        if (!in_array($type, [Server::TYPE_MIERU, Server::TYPE_SNELL], true)) {
            return [422, 'NoBrand Agent 当前仅开放 Mieru / Snell Runtime'];
        }

        $settings = $params['runtime_driver_settings']
            ?? $existing?->runtime_driver_settings
            ?? [];

        if (!is_array($settings)) {
            return [422, 'NoBrand Runtime 设置格式无效'];
        }

        foreach (['advertise_host' => 255, 'ingress_profile' => 128] as $field => $maxLength) {
            $value = $settings[$field] ?? null;
            if ($value !== null && (!is_string($value) || mb_strlen($value) > $maxLength)) {
                return [422, "NoBrand {$field} 参数无效"];
            }
        }

        if ($type === Server::TYPE_MIERU) {
            $profile = $settings['profile'] ?? 'iplc';
            if (!in_array($profile, ['iplc', 'balanced', 'stealth'], true)) {
                return [422, 'NoBrand Profile 仅支持 iplc / balanced / stealth'];
            }

            $handshake = $settings['handshake_mode'] ?? 'no-wait';
            if (!in_array($handshake, ['no-wait', 'standard'], true)) {
                return [422, 'NoBrand Handshake 仅支持 no-wait / standard'];
            }

            $multiplexing = $settings['multiplexing'] ?? 'off';
            if (!in_array($multiplexing, ['off', 'low', 'middle', 'high'], true)) {
                return [422, 'NoBrand Multiplexing 参数无效'];
            }

            $mtu = $settings['mtu'] ?? 1400;
            $mtuValid = in_array($mtu, ['safe', 'auto'], true)
                || (is_numeric($mtu) && (int) $mtu >= 1280 && (int) $mtu <= 1500);
            if (!$mtuValid) {
                return [422, 'NoBrand MTU 仅支持 safe / auto / 1280-1500'];
            }

            foreach (['pin_primary_port', 'sync_advertise_host'] as $field) {
                if (array_key_exists($field, $settings) && !is_bool($settings[$field])) {
                    return [422, "NoBrand {$field} 必须为布尔值"];
                }
            }
        }

        if ($type === Server::TYPE_SNELL) {
            $protocolSettings = $params['protocol_settings']
                ?? $existing?->protocol_settings
                ?? [];

            $version = (int) data_get($protocolSettings, 'version', 5);
            if ($version !== 5) {
                return [422, 'NoBrand Snell Runtime 当前仅开放 v5'];
            }

            if ((bool) data_get($protocolSettings, 'quic', false)) {
                return [422, 'NoBrand Snell v5 QUIC Proxy 暂不开放自动托管'];
            }
        }

        if (!$machineId) {
            return [422, 'NoBrand Runtime 节点必须绑定 NoBrand Hybrid 机器'];
        }

        $machine = ServerMachine::find($machineId);
        if (!$machine || $machine->agent_driver !== 'nobrand-hybrid') {
            return [422, '所选机器未启用 NoBrand Hybrid Agent'];
        }

        if ($type === Server::TYPE_MIERU) {
            $duplicate = Server::query()
                ->where('machine_id', $machineId)
                ->where('runtime_driver', 'nobrand')
                ->where('type', Server::TYPE_MIERU)
                ->when($existing, fn ($query) => $query->where('id', '!=', $existing->id))
                ->exists();

            if ($duplicate) {
                return [422, '每台机器仅允许一个 NoBrand Mieru 逻辑节点'];
            }
        }

        return null;
    }

    public function getNodes(Request $request)
    {
        $servers = ServerService::getAllServers()->map(function ($item) {
            $item['groups'] = ServerGroup::whereIn('id', $item['group_ids'] ?? [])->get(['name', 'id']);
            $item['parent'] = $item->parent;
            return $item;
        });
        return $this->success($servers);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    Server::where('id', $item['id'])->update(['sort' => $item['order']]);
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);

        }
        return $this->success(true);
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();
        if ($request->input('id')) {
            $server = Server::find($request->input('id'));
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }
            if ($error = $this->validateRuntimeDriverBinding($params, $server)) {
                return $this->fail($error);
            }
            try {
                $server->update($params);
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        if ($error = $this->validateRuntimeDriverBinding($params)) {
            return $this->fail($error);
        }

        try {
            Server::create($params);
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }
    }

    public function createNoBrandSnell(Request $request)
    {
        $params = $request->validate([
            'name' => 'required|string|max:64',
            'host' => 'required|string|max:255',
            'machine_id' => 'required|integer',
            'group_ids' => 'required|array|min:1',
            'group_ids.*' => 'integer',
            'ingress_profile' => 'nullable|string|max:128',
            'advertise_host' => 'nullable|string|max:255',
            'rate' => 'nullable|numeric|min:0',
        ]);

        $groupIds = collect($params['group_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if (
            $groupIds->isEmpty()
            || ServerGroup::query()->whereIn('id', $groupIds)->count() !== $groupIds->count()
        ) {
            return $this->fail([422, '权限组无效']);
        }

        $runtimeSettings = array_filter([
            'advertise_host' => trim((string) ($params['advertise_host'] ?? '')),
            'ingress_profile' => trim((string) ($params['ingress_profile'] ?? '')),
        ], fn ($value) => $value !== '');

        $candidate = [
            'type' => Server::TYPE_SNELL,
            'machine_id' => (int) $params['machine_id'],
            'runtime_driver' => 'nobrand',
            'runtime_driver_settings' => $runtimeSettings,
            'protocol_settings' => [
                'version' => 5,
                'quic' => false,
            ],
        ];

        if ($error = $this->validateRuntimeDriverBinding($candidate)) {
            return $this->fail($error);
        }

        try {
            $server = Server::create([
                'type' => Server::TYPE_SNELL,
                'name' => trim($params['name']),
                'host' => trim($params['host']),
                // Snell NoBrand nodes are logical subscription/permission
                // objects. Real per-user ports live in NoBrandUserBinding.
                'port' => 1,
                'server_port' => 1,
                'group_ids' => $groupIds->map(fn ($id) => (string) $id)->all(),
                'route_ids' => [],
                'tags' => ['nobrand', 'snell-v5'],
                'show' => true,
                'enabled' => true,
                'rate' => (float) ($params['rate'] ?? 1),
                'sort' => ((int) Server::max('sort')) + 1,
                'protocol_settings' => [
                    'version' => 5,
                    'quic' => false,
                ],
                'machine_id' => (int) $params['machine_id'],
                'runtime_driver' => 'nobrand',
                'runtime_driver_settings' => $runtimeSettings,
                'transfer_enable' => 0,
                'u' => 0,
                'd' => 0,
            ]);

            return $this->success([
                'id' => $server->id,
                'name' => $server->name,
                'type' => $server->type,
            ]);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建 NoBrand Snell 节点失败']);
        }
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'show' => 'nullable|integer',
            'machine_id' => 'nullable|integer',
            'runtime_driver' => 'nullable|string|in:native,nobrand',
            'runtime_driver_settings' => 'nullable|array',
            'enabled' => 'nullable|boolean',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        if (array_key_exists('show', $params)) {
            $server->show = (int) $params['show'];
        }
        if (array_key_exists('machine_id', $params)) {
            $server->machine_id = $params['machine_id'] ?: null;
        }
        if (array_key_exists('runtime_driver', $params)) {
            $server->runtime_driver = $params['runtime_driver'];
        }
        if (array_key_exists('runtime_driver_settings', $params)) {
            $server->runtime_driver_settings = $params['runtime_driver_settings'];
        }
        if (array_key_exists('enabled', $params)) {
            $server->enabled = (bool) $params['enabled'];
        }

        if ($error = $this->validateRuntimeDriverBinding([
            'machine_id' => $server->machine_id,
            'runtime_driver' => $server->runtime_driver,
        ], $server)) {
            return $this->fail($error);
        }

        if (!$server->save()) {
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    /**
     * 删除
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        if ($server->delete() === false) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }

    /**
     * 批量删除节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要删除的节点']);
        }

        try {
            $deleted = Server::whereIn('id', $ids)->delete();
            if ($deleted === false) {
                return $this->fail([500, '批量删除失败']);
            }
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量删除失败']);
        }
    }

    /**
     * 重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetTraffic(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        try {
            $server->u = 0;
            $server->d = 0;
            $server->save();
            
            Log::info("Server {$server->id} ({$server->name}) traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '重置失败']);
        }
    }

    /**
     * 批量重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchResetTraffic(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要重置的节点']);
        }

        try {
            Server::whereIn('id', $ids)->update([
                'u' => 0,
                'd' => 0,
            ]);
            
            Log::info("Servers " . implode(',', $ids) . " traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量重置失败']);
        }
    }

    /**
     * 批量更新节点属性（show等）
     */
    public function batchUpdate(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'show' => 'nullable|integer|in:0,1',
            'enabled' => 'nullable|boolean',
            'machine_id' => 'nullable|integer',
            'runtime_driver' => 'nullable|string|in:native,nobrand',
        ]);

        $ids = $params['ids'];
        if (empty($ids)) {
            return $this->fail([400, '请选择要更新的节点']);
        }

        $update = [];
        if (array_key_exists('show', $params) && $params['show'] !== null) {
            $update['show'] = (int) $params['show'];
        }
        if (array_key_exists('enabled', $params) && $params['enabled'] !== null) {
            $update['enabled'] = (bool) $params['enabled'];
        }
        if (array_key_exists('machine_id', $params)) {
            $update['machine_id'] = $params['machine_id'] ?: null;
        }
        if (array_key_exists('runtime_driver', $params)) {
            $update['runtime_driver'] = $params['runtime_driver'];
        }

        if (empty($update)) {
            return $this->fail([400, '没有可更新的字段']);
        }

        try {
            $servers = Server::whereIn('id', $ids)->get();
            DB::transaction(function () use ($servers, $update) {
                /** @var Server $server */
                foreach ($servers as $server) {
                    $candidate = array_merge([
                        'machine_id' => $server->machine_id,
                        'runtime_driver' => $server->runtime_driver ?? 'native',
                    ], $update);

                    if ($error = $this->validateRuntimeDriverBinding($candidate, $server)) {
                        throw new \InvalidArgumentException($error[1]);
                    }

                    $server->update($update);
                }
            });
            return $this->success(true);
        } catch (\InvalidArgumentException $e) {
            return $this->fail([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量更新失败']);
        }
    }

    /**
     * 复制节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $server = Server::find($request->input('id'));
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        $copiedServer = $server->replicate();
        $copiedServer->show = 0;
        $copiedServer->code = null;
        $copiedServer->u = 0;
        $copiedServer->d = 0;
        $copiedServer->save();

        return $this->success(true);
    }

    /**
     * Generate ECH (Encrypted Client Hello) key pair.
     * Returns PEM-encoded ECH key (server-side) and ECH config (client-side).
     */
    public function generateEchKey(Request $request)
    {
        $publicName = $request->input('public_name', 'ech.example.com');
        if (strlen($publicName) < 1 || strlen($publicName) > 253) {
            throw new ApiException('public_name must be a valid domain (1-253 bytes)');
        }

        // Generate X25519 key pair
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        // Build ECHConfigContents (draft-ietf-tls-esni-18)
        $contents = '';
        $contents .= pack('C', $configId);                // config_id
        $contents .= pack('n', 0x0020);                   // kem_id: DHKEM(X25519)
        $contents .= pack('n', 32) . $publicKey;          // public_key (length-prefixed)
        // cipher_suites: 2 suites × 4 bytes = 8 bytes
        $contents .= pack('n', 8);                        // cipher_suites byte length
        $contents .= pack('nn', 0x0001, 0x0001);          // HKDF-SHA256 + AES-128-GCM
        $contents .= pack('nn', 0x0001, 0x0003);          // HKDF-SHA256 + ChaCha20Poly1305
        $contents .= pack('C', 0);                        // max_name_length
        $contents .= pack('C', strlen($publicName)) . $publicName;
        $contents .= pack('n', 0);                        // extensions: empty

        // ECHConfig = version(2) + length(2) + contents
        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($contents)) . $contents;

        // ECHConfigList = total_length(2) + configs
        $echConfigList = pack('n', strlen($echConfig)) . $echConfig;

        // ECH Keys = private_key_len(2) + key(32) + config_len(2) + config
        $echKeysPayload = pack('n', 32) . $privateKey . pack('n', strlen($echConfig)) . $echConfig;

        $keyPem = "-----BEGIN ECH KEYS-----\n"
            . chunk_split(base64_encode($echKeysPayload), 64, "\n")
            . "-----END ECH KEYS-----";

        $configPem = "-----BEGIN ECH CONFIGS-----\n"
            . chunk_split(base64_encode($echConfigList), 64, "\n")
            . "-----END ECH CONFIGS-----";

        return $this->success([
            'key' => $keyPem,
            'config' => $configPem,
        ]);
    }
}
