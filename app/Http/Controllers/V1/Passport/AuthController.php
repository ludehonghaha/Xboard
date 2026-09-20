<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Services\Auth\LoginService;
use App\Services\Auth\RegisterService;
use App\Services\AuthService;

class AuthController extends Controller
{
    protected RegisterService $registerService;
    protected LoginService $loginService;

    public function __construct(
        RegisterService $registerService,
        LoginService $loginService
    ) {
        $this->registerService = $registerService;
        $this->loginService = $loginService;
    }

    /**
     * Invite-only registration.
     */
    public function register(AuthRegister $request)
    {
        [$success, $result] = $this->registerService->register($request);

        if (!$success) {
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }

    /**
     * Password login.
     */
    public function login(AuthLogin $request)
    {
        [$success, $result] = $this->loginService->login(
            $request->input('email'),
            $request->input('password')
        );

        if (!$success) {
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }
}
