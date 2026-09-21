<?php

use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$renderLitePortal = function (Request $request) {
    if (admin_setting('app_url') && admin_setting('safe_mode_enable', 0)) {
        $requestHost = $request->getHost();
        $configHost = parse_url(admin_setting('app_url'), PHP_URL_HOST);
        if ($requestHost !== $configHost) {
            abort(403);
        }
    }

    return view('lite', [
        'title' => admin_setting('app_name', 'XBoard Lite'),
        'version' => app(UpdateService::class)->getCurrentVersion(),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        ),
    ]);
};

Route::get('/', $renderLitePortal);

Route::get(
    '/' . admin_setting(
        'secure_path',
        admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
    ),
    $renderLitePortal
);

Route::get(
    '/' . admin_setting('subscribe_path', 's') . '/{token}',
    [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe']
)->middleware('client')->name('client.subscribe');
