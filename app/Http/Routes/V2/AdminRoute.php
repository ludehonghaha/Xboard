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
use App\Http\Controllers\V2\Admin\SystemController;
use App\Http\Controllers\V2\Admin\TrafficResetController;
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
                $router->post('/setTelegramWebhook', [ConfigController::class, 'setTelegramWebhook']);
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
                $router->get('/generateRealityKey', [ManageController::class, 'generateRealityKey']);
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
            $router->get('/stat/getStats', [StatController::class, 'getStats']);
            $router->get('/stat/getServerLastRank', [StatController::class, 'getServerLastRank']);
            $router->get('/stat/getServerYesterdayRank', [StatController::class, 'getServerYesterdayRank']);
            $router->get('/stat/getTrafficRank', [StatController::class, 'getTrafficRank']);
            $router->get('/stat/getStatRecord', [StatController::class, 'getStatRecord']);

            $router->group(['prefix' => 'traffic-reset'], function ($router) {
                $router->get('/logs', [TrafficResetController::class, 'logs']);
                $router->get('/stats', [TrafficResetController::class, 'stats']);
                $router->post('/reset-user', [TrafficResetController::class, 'resetUser']);
                $router->get('/user/{userId}/history', [TrafficResetController::class, 'userHistory']);
            });

            $router->group(['prefix' => 'system'], function ($router) {
                $router->get('/status', [SystemController::class, 'getSystemStatus']);
                $router->get('/queue-stats', [SystemController::class, 'getQueueStats']);
                $router->get('/queue-workload', [SystemController::class, 'getQueueWorkload']);
                $router->get('/audit-log', [SystemController::class, 'getAuditLog']);
                $router->get('/failed-jobs', [SystemController::class, 'getHorizonFailedJobs']);
            });

            $router->group(['prefix' => 'access-invite'], function ($router) {
                $router->get('/fetch', [AccessInviteController::class, 'fetch']);
                $router->post('/generate', [AccessInviteController::class, 'generate']);
                $router->post('/drop', [AccessInviteController::class, 'drop']);
            });
        });
    }
}
