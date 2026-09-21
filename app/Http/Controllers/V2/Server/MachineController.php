<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\NoBrandPolicyBinding;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NoBrand\NoBrandInstaller;
use App\Services\NoBrand\NoBrandPolicyService;
use App\Services\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Return desired Mieru account policy for a standalone NoBrand machine.
     *
     * This endpoint never returns protocol install/reconfigure instructions.
     */
    public function nobrandPolicies(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);

        if (($machine->agent_driver ?: 'xboard-node') !== NoBrandInstaller::DRIVER) {
            abort(409, 'Machine is not a NoBrand-OneClick policy target');
        }

        $bindings = NoBrandPolicyBinding::query()
            ->with('user')
            ->where('machine_id', $machine->id)
            ->where('sync_enabled', true)
            ->orderBy('id')
            ->get();

        $machine->forceFill([
            'policy_last_seen_at' => now()->timestamp,
        ])->saveQuietly();

        return response()->json([
            'policy_version' => 1,
            'poll_interval' => 30,
            'policies' => $bindings
                ->map(fn (NoBrandPolicyBinding $binding) => NoBrandPolicyService::desiredPolicy($binding))
                ->values(),
        ]);
    }

    /**
     * Record reconciliation result from the narrow NoBrand policy agent.
     */
    public function nobrandPolicyStatus(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);

        if (($machine->agent_driver ?: 'xboard-node') !== NoBrandInstaller::DRIVER) {
            abort(409, 'Machine is not a NoBrand-OneClick policy target');
        }

        $params = $request->validate([
            'agent_version' => 'required|string|max:32',
            'results' => 'present|array|max:5000',
            'results.*.binding_id' => 'required|integer|min:1',
            'results.*.ok' => 'required|boolean',
            'results.*.message' => 'nullable|string|max:1000',
            'results.*.remote_state' => 'nullable|array',
        ]);

        $ok = 0;
        $errors = 0;
        $now = now()->timestamp;

        foreach ($params['results'] as $result) {
            $binding = NoBrandPolicyBinding::query()
                ->where('id', (int) $result['binding_id'])
                ->where('machine_id', $machine->id)
                ->first();

            if (!$binding) {
                continue;
            }

            $success = (bool) $result['ok'];
            $success ? $ok++ : $errors++;

            $binding->forceFill([
                'last_status' => $success ? 'ok' : 'error',
                'last_message' => $result['message'] ?? null,
                'last_remote_state' => $result['remote_state'] ?? null,
                'last_applied_at' => $success ? $now : $binding->last_applied_at,
            ])->save();
        }

        $machine->forceFill([
            'policy_last_seen_at' => $now,
            'policy_status' => [
                'agent_version' => $params['agent_version'],
                'ok' => $ok,
                'errors' => $errors,
                'updated_at' => $now,
            ],
        ])->saveQuietly();

        return response()->json([
            'data' => true,
            'ok' => $ok,
            'errors' => $errors,
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
