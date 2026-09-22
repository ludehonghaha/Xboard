<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\User\UserController;
use App\Http\Controllers\V1\User\TelegramController;
use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'user'
        ], function ($router) {
            $router->get('/resetSecurity', [UserController::class, 'resetSecurity']);
            $router->get('/info', [UserController::class, 'info']);
            $router->post('/changePassword', [UserController::class, 'changePassword']);
            $router->get('/getSubscribe', [UserController::class, 'getSubscribe']);
            $router->get('/checkLogin', [UserController::class, 'checkLogin']);
            $router->get('/getActiveSession', [UserController::class, 'getActiveSession']);
            $router->post('/removeActiveSession', [UserController::class, 'removeActiveSession']);
            $router->get('/telegram/getBotInfo', [TelegramController::class, 'getBotInfo']);
        });
    }
}
