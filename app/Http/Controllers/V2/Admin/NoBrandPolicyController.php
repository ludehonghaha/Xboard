<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\NoBrandPolicyBinding;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NoBrand\NoBrandInstaller;
use App\Services\NoBrand\NoBrandPolicyService;
use Illuminate\Http\Request;

class NoBrandPolicyController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'nullable|integer|exists:v2_server_machine,id',
        ]);

        $query = NoBrandPolicyBinding::query()
            ->with([
                'machine:id,name,agent_driver,policy_last_seen_at,policy_status',
                'user:id,email,plan_id,transfer_enable,speed_limit,expired_at,banned',
            ])
            ->orderBy('id');

        if (!empty($params['machine_id'])) {
            $query->where('machine_id', (int) $params['machine_id']);
        }

        $rows = $query->get()->map(function (NoBrandPolicyBinding $binding) {
            return [
                'id' => $binding->id,
                'machine_id' => $binding->machine_id,
                'machine_name' => $binding->machine?->name,
                'user_id' => $binding->user_id,
                'email' => $binding->user?->email,
                'remote_user' => $binding->remote_user,
                'quota_mode' => $binding->quota_mode,
                'quota_days' => $binding->quota_days,
                'sync_enabled' => (bool) $binding->sync_enabled,
                'last_status' => $binding->last_status,
                'last_message' => $binding->last_message,
                'last_remote_state' => $binding->last_remote_state,
                'last_applied_at' => $binding->last_applied_at,
                'desired' => $binding->user
                    ? NoBrandPolicyService::desiredPolicy($binding)
                    : null,
            ];
        });

        return $this->success($rows);
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_nobrand_policy_binding,id',
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'email' => 'required|email',
            'remote_user' => 'required|string|min:1|max:64',
            'quota_mode' => 'required|string|in:rolling,calendar',
            'quota_days' => 'required|integer|min:1|max:3650',
            'sync_enabled' => 'nullable|boolean',
        ]);

        $machine = ServerMachine::find((int) $params['machine_id']);
        if (($machine->agent_driver ?: 'xboard-node') !== NoBrandInstaller::DRIVER) {
            return $this->fail([422, '策略只能绑定 NoBrand-OneClick 独立机器']);
        }

        $user = User::byEmail($params['email'])->first();
        if (!$user) {
            return $this->fail([422, 'Xboard 用户不存在']);
        }

        // Upstream accepts 1-64 bytes without control characters.
        if (preg_match('/[\x00-\x1F\x7F]/u', $params['remote_user'])) {
            return $this->fail([422, 'NoBrand 用户名不能包含控制字符']);
        }

        $binding = !empty($params['id'])
            ? NoBrandPolicyBinding::find((int) $params['id'])
            : new NoBrandPolicyBinding();

        $binding->fill([
            'machine_id' => (int) $params['machine_id'],
            'user_id' => (int) $user->id,
            'remote_user' => $params['remote_user'],
            'quota_mode' => $params['quota_mode'],
            'quota_days' => (int) $params['quota_days'],
            'sync_enabled' => (bool) ($params['sync_enabled'] ?? true),
            'last_status' => 'pending',
            'last_message' => null,
        ]);

        try {
            $binding->save();
        } catch (\Throwable $e) {
            return $this->fail([422, '该 Xboard 用户或 NoBrand 用户名已在这台机器建立映射']);
        }

        $binding->load('user');

        return $this->success([
            'id' => $binding->id,
            'desired' => NoBrandPolicyService::desiredPolicy($binding),
        ]);
    }

    public function drop(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_nobrand_policy_binding,id',
        ]);

        NoBrandPolicyBinding::where('id', (int) $params['id'])->delete();

        return $this->success(true);
    }
}
