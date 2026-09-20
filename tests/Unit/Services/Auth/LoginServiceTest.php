<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\LoginService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LoginServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoginService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        admin_setting([
            'password_limit_enable' => 1,
            'password_limit_count' => 5,
            'password_limit_expire' => 60,
        ]);

        $this->service = app(LoginService::class);
    }

    public function test_login_accepts_valid_password(): void
    {
        $user = $this->createUser('user@example.com', 'correct-password');

        [$success, $result] = $this->service->login($user->email, 'correct-password');

        $this->assertTrue($success);
        $this->assertSame($user->id, $result->id);
    }

    public function test_login_rejects_invalid_password(): void
    {
        $user = $this->createUser('user@example.com', 'correct-password');

        [$success, $result] = $this->service->login($user->email, 'wrong-password');

        $this->assertFalse($success);
        $this->assertSame(400, $result[0]);
    }

    public function test_login_rejects_banned_user(): void
    {
        $user = $this->createUser('user@example.com', 'correct-password');
        $user->banned = 1;
        $user->save();

        [$success, $result] = $this->service->login($user->email, 'correct-password');

        $this->assertFalse($success);
        $this->assertSame(400, $result[0]);
    }

    private function createUser(string $email, string $password): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
