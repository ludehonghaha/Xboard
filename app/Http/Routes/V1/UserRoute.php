<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\User\CommController;
use App\Http\Controllers\V1\User\GiftCardController;
use App\Http\Controllers\V1\User\KnowledgeController;
use App\Http\Controllers\V1\User\ServerController;
use App\Http\Controllers\V1\User\StatController;
use App\Http\Controllers\V1\User\TelegramController;
use App\Http\Controllers\V1\User\UserController;
use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'user'
        ], function ($router) {
            // User
            $router->get('/resetSecurity', [UserController::class, 'resetSecurity']);
            $router->get('/info', [UserController::class, 'info']);
            $router->post('/changePassword', [UserController::class, 'changePassword']);
            $router->post('/update', [UserController::class, 'update']);
            $router->get('/getSubscribe', [UserController::class, 'getSubscribe']);
            $router->get('/getStat', [UserController::class, 'getStat']);
            $router->get('/checkLogin', [UserController::class, 'checkLogin']);
            $router->get('/getActiveSession', [UserController::class, 'getActiveSession']);
            $router->post('/removeActiveSession', [UserController::class, 'removeActiveSession']);

            // Server
            $router->get('/server/fetch', [ServerController::class, 'fetch']);

            // Gift Card (kept for now; user frontend will be redesigned separately)
            $router->post('/gift-card/check', [GiftCardController::class, 'check']);
            $router->post('/gift-card/redeem', [GiftCardController::class, 'redeem']);
            $router->get('/gift-card/history', [GiftCardController::class, 'history']);
            $router->get('/gift-card/detail', [GiftCardController::class, 'detail']);
            $router->get('/gift-card/types', [GiftCardController::class, 'types']);

            // Telegram
            $router->get('/telegram/getBotInfo', [TelegramController::class, 'getBotInfo']);

            // Comm
            $router->get('/comm/config', [CommController::class, 'config']);

            // Knowledge
            $router->get('/knowledge/fetch', [KnowledgeController::class, 'fetch']);
            $router->get('/knowledge/getCategory', [KnowledgeController::class, 'getCategory']);

            // Stat
            $router->get('/stat/getTrafficLog', [StatController::class, 'getTrafficLog']);
        });
    }
}
