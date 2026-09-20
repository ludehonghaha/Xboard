<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessNoBrandTrafficReportJob;
use App\Models\NoBrandTrafficReport;
use App\Models\NoBrandUserBinding;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Models\ServerMachineLoadHistory;
use App\Services\ServerService;
use App\Services\NoBrand\NoBrandDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * machine controller
 */
class MachineController extends Controller
{
    /**
     * get nodes list for machine
     */
    public function nodes(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);

        $nodes = ServerService::getMachineNodes($machine)
            ->map(fn($node) => [
                'id' => $node->id,
                'type' => $node->type,
                'name' => $node->name,
            ])->values();

        return response()->json([
            'nodes' => $nodes,
            'base_config' => [
                'push_interval' => (int) admin_setting('server_push_interval', 60),
                'pull_interval' => (int) admin_setting('server_pull_interval', 60),
            ],
        ]);
    }

    /**
     * Desired state for the NoBrand companion runtime.
     *
     * This endpoint returns declarative node state only. It never accepts
     * arbitrary shell commands from the panel.
     */
    public function nobrandNodes(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);

        if (($machine->agent_driver ?: 'xboard-node') !== NoBrandDriver::DRIVER) {
            abort(409, 'Machine is not configured for NoBrand Hybrid');
        }

        $nodes = ServerService::getNoBrandMachineNodes($machine)
            ->map(function (Server $node) {
                $desiredUsers = [];

                if (in_array($node->type, [Server::TYPE_MIERU, Server::TYPE_SNELL, Server::TYPE_HYSTERIA], true)) {
                    $available = ServerService::getAvailableUsers($node);
                    $userIds = $available->pluck('id')->map(fn ($id) => (int) $id)->all();

                    $details = empty($userIds)
                        ? collect()
                        : User::query()
                            ->whereIn('id', $userIds)
                            ->get(['id', 'uuid', 'speed_limit', 'expired_at'])
                            ->keyBy('id');

                    $desiredUsers = $available
                        ->map(function ($user) use ($details, $node) {
                            $detail = $details->get((int) $user->id);
                            $expiredAt = $detail?->expired_at;
                            $userId = (int) $user->id;

                            return [
                                'user_id' => $userId,
                                'remote_user' => match ($node->type) {
                                    Server::TYPE_SNELL => 'xbn' . (int) $node->id . 'u' . $userId,
                                    Server::TYPE_HYSTERIA => 'xbh' . $userId,
                                    default => 'xb' . $userId,
                                },
                                // Mieru password / Snell PSK reuse the existing
                                // Xboard UUID. Companion must never log it.
                                'password' => (string) $user->uuid,
                                'bandwidth_mbps' => max(0, (int) ($detail?->speed_limit ?? 0)),
                                'expire' => $expiredAt ? date('Y-m-d', (int) $expiredAt) : '0',
                                'quota_mb' => 0,
                                'quota_days' => 0,
                                'enabled' => true,
                            ];
                        })
                        ->values()
                        ->all();
                }

                return [
                    'id' => $node->id,
                    'name' => $node->name,
                    'type' => $node->type,
                    'host' => $node->host,
                    'port' => $node->port,
                    'server_port' => $node->server_port,
                    'protocol_settings' => $node->protocol_settings,
                    'runtime_driver_settings' => $node->runtime_driver_settings,
                    'users' => $desiredUsers,
                    'updated_at' => $node->updated_at,
                ];
            })
            ->values();

        return response()->json([
            'driver' => NoBrandDriver::capabilities(),
            'agent_settings' => $machine->agent_settings ?? [],
            'nodes' => $nodes,
        ]);
    }

    /**
     * Report reconciled NoBrand per-user endpoints.
     *
     * Passwords are intentionally not accepted here. The panel remains the
     * authority for user credentials; the companion may only report runtime
     * identity and endpoint metadata.
     */
    public function nobrandBindings(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);

        if (($machine->agent_driver ?: 'xboard-node') !== NoBrandDriver::DRIVER) {
            abort(409, 'Machine is not configured for NoBrand Hybrid');
        }

        $params = $request->validate([
            'node_ids' => 'present|array|max:1000',
            'node_ids.*' => 'integer|min:1',
            'bindings' => 'present|array|max:5000',
            'bindings.*.node_id' => 'required|integer',
            'bindings.*.user_id' => 'required|integer',
            'bindings.*.remote_user' => 'required|string|max:128',
            'bindings.*.instance_id' => 'nullable|string|max:64',
            'bindings.*.display_host' => 'nullable|string|max:255',
            'bindings.*.display_port' => 'required|integer|min:1|max:65535',
            'bindings.*.transport' => 'required|string|in:TCP,UDP,BOTH',
            'bindings.*.enabled' => 'nullable|boolean',
            'bindings.*.runtime_meta' => 'nullable|array',
        ]);

        $nodeIds = collect($params['node_ids'])
            ->merge(collect($params['bindings'])->pluck('node_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $nodes = $nodeIds->isEmpty()
            ? collect()
            : Server::query()
                ->where('machine_id', $machine->id)
                ->where('runtime_driver', 'nobrand')
                ->whereIn('type', [Server::TYPE_MIERU, Server::TYPE_SNELL, Server::TYPE_HYSTERIA])
                ->whereIn('id', $nodeIds)
                ->get()
                ->keyBy('id');

        if ($nodes->count() !== $nodeIds->count()) {
            abort(422, 'Binding snapshot references a node not owned by this NoBrand Hybrid machine');
        }

        $userIds = collect($params['bindings'])
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($userIds->isNotEmpty()) {
            $existingUserIds = User::query()
                ->whereIn('id', $userIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

            if ($existingUserIds->count() !== $userIds->count()) {
                abort(422, 'Binding snapshot references an unknown Xboard user');
            }
        }

        $syncedAt = now()->timestamp;

        DB::transaction(function () use ($params, $nodeIds, $syncedAt) {
            $bindingsByNode = collect($params['bindings'])->groupBy(
                fn ($binding) => (int) $binding['node_id']
            );

            // node_ids makes this endpoint an authoritative snapshot for the
            // listed NoBrand nodes. Missing bindings are stale and must not
            // remain available to subscription rendering.
            foreach ($nodeIds as $nodeId) {
                $reportedUserIds = collect($bindingsByNode->get($nodeId, []))
                    ->pluck('user_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $stale = NoBrandUserBinding::query()
                    ->where('server_id', (int) $nodeId);

                if (!empty($reportedUserIds)) {
                    $stale->whereNotIn('user_id', $reportedUserIds);
                }

                $stale->delete();
            }

            foreach ($params['bindings'] as $binding) {
                $record = NoBrandUserBinding::query()->firstOrNew([
                    'server_id' => (int) $binding['node_id'],
                    'user_id' => (int) $binding['user_id'],
                ]);

                $existingMeta = is_array($record->runtime_meta) ? $record->runtime_meta : [];
                $reportedMeta = is_array($binding['runtime_meta'] ?? null)
                    ? $binding['runtime_meta']
                    : [];

                // The traffic watermark is panel-owned accounting state. The
                // companion may report runtime metadata but cannot overwrite
                // or rewind this value through the binding endpoint.
                unset($reportedMeta['traffic_watermark']);

                $record->fill([
                    'remote_user' => $binding['remote_user'],
                    'instance_id' => $binding['instance_id'] ?? null,
                    'display_host' => $binding['display_host'] ?? null,
                    'display_port' => (int) $binding['display_port'],
                    'transport' => strtoupper($binding['transport']),
                    'enabled' => (bool) ($binding['enabled'] ?? true),
                    'runtime_meta' => array_merge($existingMeta, $reportedMeta),
                    'last_synced_at' => $syncedAt,
                ]);

                $record->save();
            }
        });

        return response()->json([
            'data' => true,
            'synced_at' => $syncedAt,
            'node_count' => $nodeIds->count(),
            'count' => count($params['bindings']),
        ]);
    }

    /**
     * Persist absolute NoBrand Mieru traffic counters for asynchronous,
     * idempotent accounting.
     */
    public function nobrandTraffic(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);

        if (($machine->agent_driver ?: 'xboard-node') !== NoBrandDriver::DRIVER) {
            abort(409, 'Machine is not configured for NoBrand Hybrid');
        }

        $params = $request->validate([
            'node_id' => 'required|integer',
            'report_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'observed_at' => 'required|integer|min:1',
            'readings' => 'required|array|max:5000',
            'readings.*.user_id' => 'required|integer|min:1',
            'readings.*.instance_id' => ['required', 'string', 'max:64', 'regex:/^u[0-9a-f]{16}$/'],
            'readings.*.upload_bytes' => 'required|integer|min:0',
            'readings.*.download_bytes' => 'required|integer|min:0',
        ]);

        $node = Server::query()
            ->where('id', (int) $params['node_id'])
            ->where('machine_id', $machine->id)
            ->where('runtime_driver', 'nobrand')
            ->where('type', Server::TYPE_MIERU)
            ->first();

        if (!$node) {
            abort(422, 'Traffic report references a node not owned by this NoBrand Hybrid machine');
        }

        $report = NoBrandTrafficReport::query()->firstOrCreate(
            ['report_id' => $params['report_id']],
            [
                'machine_id' => $machine->id,
                'server_id' => $node->id,
                'readings' => array_values($params['readings']),
                'observed_at' => (int) $params['observed_at'],
                'status' => 'pending',
            ]
        );

        if (
            (int) $report->machine_id !== (int) $machine->id
            || (int) $report->server_id !== (int) $node->id
        ) {
            abort(409, 'Traffic report ID is already owned by another node');
        }

        if ($report->status !== 'processed') {
            ProcessNoBrandTrafficReportJob::dispatch((int) $report->id);
        }

        return response()->json([
            'data' => true,
            'report_id' => $report->report_id,
            'status' => $report->status,
        ]);
    }

    /**
     * Report NoBrand companion heartbeat and last reconciliation result.
     */
    public function nobrandStatus(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);

        if (($machine->agent_driver ?: 'xboard-node') !== NoBrandDriver::DRIVER) {
            abort(409, 'Machine is not configured for NoBrand Hybrid');
        }

        $params = $request->validate([
            'state' => 'required|string|in:ok,error',
            'version' => 'required|string|max:32',
            'message' => 'nullable|string|max:1000',
            'managed_nodes' => 'nullable|integer|min:0|max:10000',
            'managed_users' => 'nullable|integer|min:0|max:1000000',
            'bindings' => 'nullable|integer|min:0|max:1000000',
            'traffic_readings' => 'nullable|integer|min:0|max:1000000',
            'snell_meter_mode' => 'nullable|string|in:off,nft',
            'snell_meter_readings' => 'nullable|integer|min:0|max:1000000',
            'hy2_clients' => 'nullable|integer|min:0|max:1000000',
            'reconcile_ms' => 'nullable|integer|min:0|max:3600000',
        ]);

        $now = now()->timestamp;
        $machine->forceFill([
            'nobrand_status' => [
                'state' => $params['state'],
                'version' => $params['version'],
                'message' => $params['message'] ?? null,
                'managed_nodes' => (int) ($params['managed_nodes'] ?? 0),
                'managed_users' => (int) ($params['managed_users'] ?? 0),
                'bindings' => (int) ($params['bindings'] ?? 0),
                'traffic_readings' => (int) ($params['traffic_readings'] ?? 0),
                'snell_meter_mode' => (string) ($params['snell_meter_mode'] ?? 'off'),
                'snell_meter_readings' => (int) ($params['snell_meter_readings'] ?? 0),
                'hy2_clients' => (int) ($params['hy2_clients'] ?? 0),
                'reconcile_ms' => (int) ($params['reconcile_ms'] ?? 0),
                'updated_at' => $now,
            ],
            'nobrand_last_seen_at' => $now,
        ])->saveQuietly();

        return response()->json([
            'data' => true,
            'recorded_at' => $now,
        ]);
    }

    /**
     * report machine status
     */
    public function status(Request $request): JsonResponse
    {
        $request->validate([
            'cpu' => 'required|numeric|min:0|max:100',
            'mem.total' => 'required|integer|min:0',
            'mem.used' => 'required|integer|min:0',
            'swap.total' => 'nullable|integer|min:0',
            'swap.used' => 'nullable|integer|min:0',
            'disk.total' => 'nullable|integer|min:0',
            'disk.used' => 'nullable|integer|min:0',
            'net.in_speed' => 'nullable|numeric|min:0',
            'net.out_speed' => 'nullable|numeric|min:0',
        ]);

        $machine = $this->authenticateMachine($request);
        $recordedAt = now()->timestamp;

        $loadStatus = [
            'cpu' => (float) $request->input('cpu'),
            'mem' => [
                'total' => (int) $request->input('mem.total'),
                'used' => (int) $request->input('mem.used'),
            ],
            'swap' => [
                'total' => (int) $request->input('swap.total', 0),
                'used' => (int) $request->input('swap.used', 0),
            ],
            'disk' => [
                'total' => (int) $request->input('disk.total', 0),
                'used' => (int) $request->input('disk.used', 0),
            ],
            'updated_at' => $recordedAt,
        ];

        $netInSpeed = $request->input('net.in_speed');
        $netOutSpeed = $request->input('net.out_speed');

        if ($netInSpeed !== null && $netOutSpeed !== null) {
            $loadStatus['net'] = [
                'in_speed' => (float) $netInSpeed,
                'out_speed' => (float) $netOutSpeed,
            ];
        }

        $machine->forceFill([
            'load_status' => $loadStatus,
            'last_seen_at' => $recordedAt,
        ])->save();

        $historyData = [
            'machine_id' => $machine->id,
            'cpu' => (float) $request->input('cpu'),
            'mem_total' => (int) $request->input('mem.total'),
            'mem_used' => (int) $request->input('mem.used'),
            'disk_total' => (int) $request->input('disk.total', 0),
            'disk_used' => (int) $request->input('disk.used', 0),
            'recorded_at' => $recordedAt,
        ];

        if ($netInSpeed !== null && $netOutSpeed !== null) {
            $historyData['net_in_speed'] = (float) $netInSpeed;
            $historyData['net_out_speed'] = (float) $netOutSpeed;
        }

        ServerMachineLoadHistory::create($historyData);

        // Time-based cleanup: keep 24h of data, runs on ~5% of requests
        if (random_int(1, 20) === 1) {
            ServerMachineLoadHistory::query()
                ->where('machine_id', $machine->id)
                ->where('recorded_at', '<', now()->subDay()->timestamp)
                ->delete();
        }

        return response()->json(['data' => true]);
    }

    private function authenticateMachine(Request $request): ServerMachine
    {
        $request->validate([
            'machine_id' => 'required|integer',
            'token' => 'required|string',
        ]);

        $machine = ServerMachine::where('id', $request->input('machine_id'))
            ->where('token', $request->input('token'))
            ->first();

        if (!$machine || !$machine->is_active) {
            abort(403, 'Machine not found or disabled');
        }

        $machine->forceFill(['last_seen_at' => now()->timestamp])->saveQuietly();

        return $machine;
    }
}
