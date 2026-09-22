<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfigSave;
use App\Models\SubscribeTemplate;
use App\Services\TelegramService;
use App\Utils\Dict;
use Illuminate\Http\Request;

class ConfigController extends Controller
{


    public function setTelegramWebhook(Request $request)
    {
        $hookUrl = $this->resolveTelegramWebhookUrl();
        if (blank($hookUrl)) {
            return $this->fail([422, 'Telegram Webhook地址未配置']);
        }
        $hookUrl .= '?' . http_build_query([
            'access_token' => md5(admin_setting('telegram_bot_token', $request->input('telegram_bot_token')))
        ]);
        $telegramService = new TelegramService($request->input('telegram_bot_token'));
        $telegramService->getMe();
        $telegramService->setWebhook(url: $hookUrl);
        $telegramService->registerBotCommands();
        return $this->success([
            'success' => true,
            'webhook_url' => $hookUrl,
            'webhook_base_url' => $this->getTelegramWebhookBaseUrl(),
        ]);
    }

    public function fetch(Request $request)
    {
        $key = $request->input('key');
        $configMappings = $this->getConfigMappings();
        if ($key && isset($configMappings[$key])) {
            return $this->success([$key => $configMappings[$key]]);
        }

        return $this->success($configMappings);
    }

    /**
     * 获取配置映射数据
     * 
     * @return array 配置映射数组
     */
    private function getConfigMappings(): array
    {
        return [
            'site' => [
                'logo' => admin_setting('logo'),
                'force_https' => (int) admin_setting('force_https', 0),
                'stop_register' => (int) admin_setting('stop_register', 0),
                'app_name' => admin_setting('app_name', 'XBoard'),
                'app_description' => admin_setting('app_description', 'XBoard is best!'),
                'app_url' => admin_setting('app_url'),
                'subscribe_url' => admin_setting('subscribe_url'),
                'tos_url' => admin_setting('tos_url'),
            ],
            'subscribe' => [
                'reset_traffic_method' => (int) admin_setting('reset_traffic_method', 0),
                'show_info_to_server_enable' => (bool) admin_setting('show_info_to_server_enable', 0),
                'show_protocol_to_server_enable' => (bool) admin_setting('show_protocol_to_server_enable', 0),
                'default_remind_expire' => (bool) admin_setting('default_remind_expire', 1),
                'default_remind_traffic' => (bool) admin_setting('default_remind_traffic', 1),
                'subscribe_path' => admin_setting('subscribe_path', 's'),
            ],
            'server' => [
                'server_token' => admin_setting('server_token'),
                'server_pull_interval' => admin_setting('server_pull_interval', 60),
                'server_push_interval' => admin_setting('server_push_interval', 60),
                'device_limit_mode' => (int) admin_setting('device_limit_mode', 0),
                'server_ws_enable' => (bool) admin_setting('server_ws_enable', 1),
                'server_ws_url' => admin_setting('server_ws_url', ''),
            ],
            'telegram' => [
                'telegram_bot_enable' => (bool) admin_setting('telegram_bot_enable', 0),
                'telegram_bot_token' => admin_setting('telegram_bot_token'),
                'telegram_webhook_url' => admin_setting('telegram_webhook_url'),
                'telegram_discuss_link' => admin_setting('telegram_discuss_link')
            ],
            'app' => [
                'windows_version' => admin_setting('windows_version', ''),
                'windows_download_url' => admin_setting('windows_download_url', ''),
                'macos_version' => admin_setting('macos_version', ''),
                'macos_download_url' => admin_setting('macos_download_url', ''),
                'android_version' => admin_setting('android_version', ''),
                'android_download_url' => admin_setting('android_download_url', '')
            ],
            'safe' => [
                'safe_mode_enable' => (bool) admin_setting('safe_mode_enable', 0),
                'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
                'email_whitelist_enable' => (bool) admin_setting('email_whitelist_enable', 0),
                'email_whitelist_suffix' => admin_setting('email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT),
                'email_gmail_limit_enable' => (bool) admin_setting('email_gmail_limit_enable', 0),
                'register_limit_by_ip_enable' => (bool) admin_setting('register_limit_by_ip_enable', 0),
                'register_limit_count' => admin_setting('register_limit_count', 3),
                'register_limit_expire' => admin_setting('register_limit_expire', 60),
                'password_limit_enable' => (bool) admin_setting('password_limit_enable', 1),
                'password_limit_count' => admin_setting('password_limit_count', 5),
                'password_limit_expire' => admin_setting('password_limit_expire', 60),
            ],
            'subscribe_template' => [
                'subscribe_template_singbox' => $this->formatTemplateContent(
                    subscribe_template('singbox') ?? '',
                    'json'
                ),
                'subscribe_template_clash' => subscribe_template('clash') ?? '',
                'subscribe_template_clashmeta' => subscribe_template('clashmeta') ?? '',
                'subscribe_template_stash' => subscribe_template('stash') ?? '',
                'subscribe_template_surge' => subscribe_template('surge') ?? '',
                'subscribe_template_surfboard' => subscribe_template('surfboard') ?? ''
            ]
        ];
    }

    public function save(ConfigSave $request)
    {
        $data = $request->validated();

        $templateKeys = [
            'subscribe_template_singbox' => 'singbox',
            'subscribe_template_clash' => 'clash',
            'subscribe_template_clashmeta' => 'clashmeta',
            'subscribe_template_stash' => 'stash',
            'subscribe_template_surge' => 'surge',
            'subscribe_template_surfboard' => 'surfboard',
        ];

        foreach ($data as $k => $v) {
            if (isset($templateKeys[$k])) {
                SubscribeTemplate::setContent($templateKeys[$k], $v);
                continue;
            }

            if (in_array($k, ['app_url', 'server_ws_url'], true) && is_string($v)) {
                $v = rtrim(trim($v), '/');
            }

            if ($k === 'subscribe_url' && is_string($v)) {
                $v = collect(explode(',', $v))
                    ->map(fn ($url) => rtrim(trim($url), '/'))
                    ->filter()
                    ->implode(',');
            }

            admin_setting([$k => $v]);
        }

        return $this->success(true);
    }

    /**
     * 格式化模板内容
     * 
     * @param mixed $content 模板内容
     * @param string $format 输出格式 (json|string)
     * @return string 格式化后的内容
     */
    private function formatTemplateContent(mixed $content, string $format = 'string'): string
    {
        return match ($format) {
            'json' => match (true) {
                    is_array($content) => json_encode(
                        value: $content,
                        flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),

                    is_string($content) && str($content)->isJson() => rescue(
                        callback: fn() => json_encode(
                            value: json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR),
                            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                        rescue: $content,
                        report: false
                    ),

                    default => str($content)->toString()
                },

            default => str($content)->toString()
        };
    }

    private function getTelegramWebhookBaseUrl(): ?string
    {
        $customUrl = trim((string) admin_setting('telegram_webhook_url', ''));
        if ($customUrl !== '') {
            return rtrim($customUrl, '/');
        }

        $appUrl = trim((string) admin_setting('app_url', ''));
        if ($appUrl !== '') {
            return rtrim($appUrl, '/');
        }

        return null;
    }

    private function resolveTelegramWebhookUrl(): ?string
    {
        $baseUrl = $this->getTelegramWebhookBaseUrl();
        if (!$baseUrl) {
            return null;
        }

        if (str_contains($baseUrl, '/api/v1/guest/telegram/webhook')) {
            return $baseUrl;
        }

        return $baseUrl . '/api/v1/guest/telegram/webhook';
    }
}
