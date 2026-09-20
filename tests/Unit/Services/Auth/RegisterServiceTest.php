<?php

namespace Tests\Unit\Services\Auth;

use App\Models\InviteCode;
use App\Models\User;
use App\Services\Auth\RegisterService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RegisterServiceTest extends TestCase
{
    use RefreshDatabase;

    private RegisterService $service;
    private User $issuer;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        admin_setting([
            'email_whitelist_enable' => 0,
            'email_gmail_limit_enable' => 0,
            'stop_register' => 0,
            'captcha_enable' => 0,
            'register_limit_by_ip_enable' => 0,
        ]);

        $this->issuer = User::query()->create([
            'email' => 'admin@example.com',
            'password' => password_hash('admin-password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'is_admin' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->service = app(RegisterService::class);
    }

    public function test_validate_register_requires_invite_code(): void
    {
        [$success, $result] = $this->service->validateRegister($this->makeRequest());

        $this->assertFalse($success);
        $this->assertSame(422, $result[0]);
    }

    public function test_validate_register_rejects_invalid_invite_code(): void
    {
        [$success, $result] = $this->service->validateRegister(
            $this->makeRequest(['invite_code' => 'NOTVALID1234'])
        );

        $this->assertFalse($success);
        $this->assertSame(400, $result[0]);
    }

    public function test_register_consumes_invite_without_creating_referral_relation(): void
    {
        $invite = $this->createInvite('ACCESS123456');

        [$success, $user] = $this->service->register(
            $this->makeRequest(['invite_code' => $invite->code])
        );

        $this->assertTrue($success);
        $this->assertInstanceOf(User::class, $user);
        $this->assertNull($user->invite_user_id);
        $this->assertNull($user->plan_id);
        $this->assertNull($user->group_id);

        $invite->refresh();
        $this->assertTrue((bool) $invite->status);
    }

    public function test_used_invite_cannot_register_again(): void
    {
        $invite = $this->createInvite('ONETIME12345');

        [$firstSuccess] = $this->service->register(
            $this->makeRequest([
                'email' => 'first@example.com',
                'invite_code' => $invite->code,
            ])
        );

        [$secondSuccess, $secondResult] = $this->service->register(
            $this->makeRequest([
                'email' => 'second@example.com',
                'invite_code' => $invite->code,
            ])
        );

        $this->assertTrue($firstSuccess);
        $this->assertFalse($secondSuccess);
        $this->assertSame(400, $secondResult[0]);
    }

    private function createInvite(string $code): InviteCode
    {
        $invite = new InviteCode();
        $invite->user_id = $this->issuer->id;
        $invite->code = $code;
        $invite->status = InviteCode::STATUS_UNUSED;
        $invite->pv = 0;
        $invite->save();

        return $invite;
    }

    private function makeRequest(array $overrides = []): Request
    {
        return Request::create('/api/v1/passport/auth/register', 'POST', array_merge([
            'email' => 'user@example.com',
            'password' => 'password123',
        ], $overrides));
    }
}
