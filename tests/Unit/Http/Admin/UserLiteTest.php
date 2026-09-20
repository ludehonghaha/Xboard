<?php

namespace Tests\Unit\Http\Admin;

use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Requests\Admin\UserUpdate;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserLiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        admin_setting([
            'app_url' => 'https://example.com',
            'subscribe_path' => 's',
        ]);
    }

    public function test_user_transform_hides_legacy_financial_and_referral_fields(): void
    {
        $user = User::query()->create([
            'email' => 'lite-user@example.com',
            'password' => password_hash('password123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => 12345,
            'discount' => 88,
            'commission_type' => 1,
            'commission_rate' => 20,
            'commission_balance' => 500,
            'invite_user_id' => 1,
            'transfer_enable' => 10 * 1024 * 1024,
            'u' => 1024,
            'd' => 2048,
            't' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $data = UserController::transformUserData($user);

        foreach ([
            'balance',
            'discount',
            'commission_type',
            'commission_rate',
            'commission_balance',
            'invite_user_id',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }

        $this->assertSame(3072, $data['total_used']);
        $this->assertSame((10 * 1024 * 1024) - 3072, $data['remaining_traffic']);
        $this->assertTrue($data['is_online']);
        $this->assertStringContainsString('/s/', $data['subscribe_url']);
    }

    public function test_user_update_rules_do_not_accept_balance(): void
    {
        $rules = app(UserUpdate::class)->rules();

        $this->assertArrayNotHasKey('balance', $rules);
        $this->assertArrayHasKey('plan_id', $rules);
        $this->assertArrayHasKey('transfer_enable', $rules);
        $this->assertArrayHasKey('speed_limit', $rules);
        $this->assertArrayHasKey('device_limit', $rules);
        $this->assertArrayHasKey('expired_at', $rules);
    }
}
