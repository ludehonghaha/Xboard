<?php

use Illuminate\Support\Facades\Route;
use Plugin\NexaMesh\Controllers\StatusController;

Route::group([
    'prefix' => 'api/v1/nexamesh',
], function () {
    Route::get('/status', [StatusController::class, 'show']);
});
