<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Guest\TelegramController;
use Illuminate\Contracts\Routing\Registrar;

class GuestRoute
{
    public function map(Registrar $router)
    {
        $router->group(['prefix' => 'guest'], function ($router) {
            $router->post('/telegram/webhook', [TelegramController::class, 'webhook']);
        });
    }
}
