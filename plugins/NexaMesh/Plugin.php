<?php

namespace Plugin\NexaMesh;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('guest_comm_config', function ($config) {
            $config['nexamesh'] = [
                'enabled' => true,
                'nobrand' => (bool) $this->getConfig('enable_nobrand', true),
                'dual' => (bool) $this->getConfig('enable_dual', true),
            ];

            return $config;
        });
    }
}
