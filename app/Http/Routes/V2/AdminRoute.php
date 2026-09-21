<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Admin\AccessInviteController;
use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V2\Admin\PlanController;
use App\Http\Controllers\V2\Admin\Server\GroupController;
use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Controllers\V2\Admin\Server\RouteController;
use App\Http\Controllers\V2\Admin\StatController;
use App\Http\Controllers\V2\Admin\UserController;
use Illuminate\Contracts\Routing\Registrar;

class AdminRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
            'middleware' => ['admin', 'log'],
        ], function ($router) {
            $router->group(['prefix' => 'config'], function ($router) {
                $router->get('/fetch', [ConfigController::class, 'fetch']);
                $router->post('/save', [ConfigController::class, 'save']);
            });

            $router->group(['prefix' => 'plan'], function ($router) {
                $router->get('/fetch', [PlanController::class, 'fetch']);
                $router->post('/save', [PlanController::class, 'save']);
                $router->post('/drop', [PlanController::class, 'drop']);
                $router->post('/update', [PlanController::class, 'update']);
            });

            $router->group(['prefix' => 'server/group'], function ($router) {
                $router->get('/fetch', [GroupController::class, 'fetch']);
                $router->post('/save', [GroupController::class, 'save']);
                $router->post('/drop', [GroupController::class, 'drop']);
            });

            $router->group(['prefix' => 'server/route'], function ($router) {
                $router->get('/fetch', [RouteController::class, 'fetch']);
                $router->post('/save', [RouteController::class, 'save']);
                $router->post('/drop', [RouteController::class, 'drop']);
            });

            $router->group(['prefix' => 'server/manage'], function ($router) {
                $router->get('/getNodes', [ManageController::class, 'getNodes']);
                $router->post('/update', [ManageController::class, 'update']);
                $router->post('/save', [ManageController::class, 'save']);
                $router->post('/drop', [ManageController::class, 'drop']);
                $router->post('/resetTraffic', [ManageController::class, 'resetTraffic']);
                $router->post('/copy', [ManageController::class, 'copy']);
                $router->post('/sort', [ManageController::class, 'sort']);
                $router->post('/batchDelete', [ManageController::class, 'batchDelete']);
                $router->post('/batchUpdate', [ManageController::class, 'batchUpdate']);
                $router->post('/batchResetTraffic', [ManageController::class, 'batchResetTraffic']);
                $router->get('/generateEchKey', [ManageController::class, 'generateEchKey']);
                $router->post('/quickDeploy', [ManageController::class, 'quickDeploy']);
            });

            $router->group(['prefix' => 'server/machine'], function ($router) {
                $router->get('/fetch', [MachineController::class, 'fetch']);
                $router->post('/save', [MachineController::class, 'save']);
                $router->post('/drop', [MachineController::class, 'drop']);
                $router->get('/installCommand', [MachineController::class, 'installCommand']);
                $router->get('/nodes', [MachineController::class, 'nodes']);
                $router->post('/resetToken', [MachineController::class, 'resetToken']);
                $router->get('/getToken', [MachineController::class, 'getToken']);
                $router->get('/history', [MachineController::class, 'history']);
                $router->post('/sync', [MachineController::class, 'sync']);
            });

            $router->group(['prefix' => 'user'], function ($router) {
                $router->any('/fetch', [UserController::class, 'fetch']);
                $router->post('/update', [UserController::class, 'update']);
                $router->get('/getUserInfoById', [UserController::class, 'getUserInfoById']);
                $router->post('/ban', [UserController::class, 'ban']);
                $router->post('/resetSecret', [UserController::class, 'resetSecret']);
                $router->post('/generate', [UserController::class, 'generate']);
                $router->post('/destroy', [UserController::class, 'destroy']);
                $router->post('/dumpCSV', [UserController::class, 'dumpCSV']);
            });

            $router->get('/stat/liteDashboard', [StatController::class, 'liteDashboard']);

            $router->group(['prefix' => 'access-invite'], function ($router) {
                $router->get('/fetch', [AccessInviteController::class, 'fetch']);
                $router->post('/generate', [AccessInviteController::class, 'generate']);
                $router->post('/drop', [AccessInviteController::class, 'drop']);
            });
        });
    }
}
