<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;

class CommController extends Controller
{
    public function config()
    {
        return $this->success([
            'is_telegram' => (int) admin_setting('telegram_bot_enable', 0),
            'telegram_discuss_link' => admin_setting('telegram_discuss_link'),
            'currency' => admin_setting('currency', 'CNY'),
            'currency_symbol' => admin_setting('currency_symbol', '¥'),

            // Compatibility flags for the existing user frontend.
            'stripe_pk' => null,
            'withdraw_methods' => [],
            'withdraw_close' => 1,
            'commission_distribution_enable' => 0,
            'commission_distribution_l1' => 0,
            'commission_distribution_l2' => 0,
            'commission_distribution_l3' => 0,
        ]);
    }
}
