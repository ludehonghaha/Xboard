<?php

namespace App\Services\NoBrand;

use App\Models\NoBrandPolicyBinding;
use App\Models\User;

final class NoBrandPolicyService
{
    public static function desiredPolicy(NoBrandPolicyBinding $binding): array
    {
        /** @var User $user */
        $user = $binding->user;

        $quotaMb = intdiv(max(0, (int) ($user->transfer_enable ?? 0)), 1024 * 1024);
        $expiredAt = (int) ($user->expired_at ?? 0);

        $enabled = (bool) $binding->sync_enabled
            && !$user->banned
            && $user->plan_id !== null
            && ($expiredAt === 0 || $expiredAt > time())
            && $quotaMb > 0;

        return [
            'binding_id' => (int) $binding->id,
            'remote_user' => (string) $binding->remote_user,
            'quota_mb' => $quotaMb,
            'quota_days' => max(1, (int) $binding->quota_days),
            'quota_mode' => in_array($binding->quota_mode, ['rolling', 'calendar'], true)
                ? $binding->quota_mode
                : 'calendar',
            'bandwidth_mbps' => max(0, (int) ($user->speed_limit ?? 0)),
            'expire' => $expiredAt > 0 ? date('Y-m-d', $expiredAt) : '0',
            'enabled' => $enabled,
            'xboard' => [
                'user_id' => (int) $user->id,
                'email' => (string) $user->email,
                'plan_id' => $user->plan_id ? (int) $user->plan_id : null,
                'banned' => (bool) $user->banned,
                'expired_at' => $expiredAt ?: null,
            ],
        ];
    }
}
