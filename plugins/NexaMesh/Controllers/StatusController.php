<?php

namespace Plugin\NexaMesh\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\Request;

class StatusController extends PluginController
{
    public function show(Request $request)
    {
        if ($error = $this->beforePluginAction()) {
            return $error[1];
        }

        $baseUrl = trim((string) $this->getConfig('forwardx_api_base', ''));

        return $this->success([
            'plugin' => 'nexamesh',
            'version' => '0.1.0',
            'features' => [
                'nobrand' => (bool) $this->getConfig('enable_nobrand', true),
                'dual' => (bool) $this->getConfig('enable_dual', true),
                'forwardx_configured' => $baseUrl !== '',
            ],
        ]);
    }
}
