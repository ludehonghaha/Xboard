<?php

namespace App\Services\Auth;

use App\Models\InviteCode;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RegisterService
{
    /**
     * Validate invite-only registration.
     */
    public function validateRegister(Request $request): array
    {
        if ((int) admin_setting('register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int) $registerCountByIP >= (int) admin_setting('register_limit_count', 3)) {
                return [false, [429, __('Register frequently, please try again after :minute minute', [
                    'minute' => admin_setting('register_limit_expire', 60)
                ])]];
            }
        }

        if ((int) admin_setting('email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                admin_setting('email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT)
            )) {
                return [false, [400, __('Email suffix is not in the Whitelist')]];
            }
        }

        if ((int) admin_setting('email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                return [false, [400, __('Gmail alias is not supported')]];
            }
        }

        if ((int) admin_setting('stop_register', 0)) {
            return [false, [400, __('Registration has closed')]];
        }

        if (!$request->filled('invite_code')) {
            return [false, [422, __('You must use the invitation code to register')]];
        }

        $invite = InviteCode::where('code', $request->input('invite_code'))
            ->where('status', InviteCode::STATUS_UNUSED)
            ->first();

        if (!$invite) {
            return [false, [400, __('Invalid invitation code')]];
        }

        if (User::byEmail($request->input('email'))->exists()) {
            return [false, [400201, __('Email already exists')]];
        }

        return [true, null];
    }

    public function register(Request $request): array
    {
        [$valid, $error] = $this->validateRegister($request);
        if (!$valid) {
            return [false, $error];
        }

        HookManager::call('user.register.before', $request);

        try {
            [$success, $result] = DB::transaction(function () use ($request) {
                $invite = InviteCode::where('code', (string) $request->input('invite_code'))
                    ->where('status', InviteCode::STATUS_UNUSED)
                    ->lockForUpdate()
                    ->first();

                if (!$invite) {
                    return [false, [400, __('Invalid invitation code')]];
                }

                $userService = app(UserService::class);
                $user = $userService->createUser([
                    'email' => $request->input('email'),
                    'password' => $request->input('password'),
                ]);

                if (!$user->save()) {
                    throw new \RuntimeException('Register failed');
                }

                $invite->status = InviteCode::STATUS_USED;
                if (!$invite->save()) {
                    throw new \RuntimeException('Failed to consume invitation code');
                }

                return [true, $user];
            });
        } catch (\Throwable $e) {
            report($e);
            return [false, [500, __('Register failed')]];
        }

        if (!$success) {
            return [false, $result];
        }

        /** @var User $user */
        $user = $result;
        HookManager::call('user.register.after', $user);

        $user->last_login_at = time();
        $user->save();

        if ((int) admin_setting('register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            Cache::put(
                CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()),
                (int) $registerCountByIP + 1,
                (int) admin_setting('register_limit_expire', 60) * 60
            );
        }

        return [true, $user];
    }
}
