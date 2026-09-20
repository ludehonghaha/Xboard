<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Controllers\V1\Passport\CommController;
use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'passport'
        ], function ($router) {
            // Invite-only registration + password login.
            $router->post('/auth/register', [AuthController::class, 'register']);
            $router->post('/auth/login', [AuthController::class, 'login']);

            // Keep page-view statistics only. Email verification and password recovery are removed.
            $router->post('/comm/pv', [CommController::class, 'pv']);
        });
    }
}
