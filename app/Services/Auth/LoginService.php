<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class LoginService
{
    /**
     * Password login for Xboard Lite.
     *
     * @return array{0: bool, 1: mixed}
     */
    public function login(string $email, string $password): array
    {
        if ((int) admin_setting('password_limit_enable', true)) {
            $passwordErrorCount = (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int) admin_setting('password_limit_count', 5)) {
                return [
                    false,
                    [
                        429,
                        __('There are too many password errors, please try again after :minute minutes.', [
                            'minute' => admin_setting('password_limit_expire', 60)
                        ])
                    ]
                ];
            }
        }

        $user = User::byEmail($email)->first();
        if (!$user) {
            return [false, [400, __('Incorrect email or password')]];
        }

        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $password,
            $user->password
        )) {
            if ((int) admin_setting('password_limit_enable', true)) {
                $passwordErrorCount = (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    $passwordErrorCount + 1,
                    60 * (int) admin_setting('password_limit_expire', 60)
                );
            }

            return [false, [400, __('Incorrect email or password')]];
        }

        if ($user->banned) {
            return [false, [400, __('Your account has been suspended')]];
        }

        $user->last_login_at = time();
        $user->save();

        HookManager::call('user.login.after', $user);
        return [true, $user];
    }
}
