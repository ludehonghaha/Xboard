<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerMachine;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    public function getNodes(Request $request)
    {
        $servers = ServerService::getAllServers()->map(function ($item) {
            $item['groups'] = ServerGroup::whereIn('id', $item['group_ids'] ?? [])->get(['name', 'id']);
            $item['parent'] = $item->parent;
            return $item;
        });
        return $this->success($servers);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    Server::where('id', $item['id'])->update(['sort' => $item['order']]);
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);

        }
        return $this->success(true);
    }

    /**
     * Create a machine-bound node from a one-click protocol profile.
     * The Server observer notifies machine mode immediately after creation.
     */
    public function quickDeploy(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'protocol' => 'required|string|in:mieru,shadowsocks,vless,vmess,trojan,hysteria,tuic,anytls,socks,naive,http',
            'security' => 'nullable|string|in:none,tls,reality',
            'enable_ech' => 'nullable|boolean',
            'name' => 'required|string|max:100',
            'host' => 'required|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'tls_domain' => 'nullable|string|max:253',
            'reality_server_name' => 'nullable|string|max:253',
            'reality_server_port' => 'nullable|integer|min:1|max:65535',
            'show' => 'nullable|boolean',
            'group_ids' => 'nullable|array',
            'group_ids.*' => 'integer',
            'route_ids' => 'nullable|array',
            'route_ids.*' => 'integer',
        ]);

        $machine = ServerMachine::findOrFail($params['machine_id']);
        if (!$machine->is_active) {
            return $this->fail([400, '服务器已停用，不能部署节点']);
        }

        $type = $params['protocol'];
        $allowedSecurity = match ($type) {
            'vless' => ['none', 'tls', 'reality'],
            'vmess' => ['none', 'tls'],
            'trojan' => ['tls', 'reality'],
            'hysteria', 'tuic', 'anytls', 'naive' => ['tls'],
            'http' => ['none', 'tls'],
            default => ['none'],
        };
        $defaultSecurity = in_array('none', $allowedSecurity, true) ? 'none' : $allowedSecurity[0];
        $security = (string) ($params['security'] ?? $defaultSecurity);
        if (!in_array($security, $allowedSecurity, true)) {
            return $this->fail([400, "协议 {$type} 不支持安全模式 {$security}"]);
        }

        $enableEch = (bool) ($params['enable_ech'] ?? false);
        $echCapable = in_array($type, ['vless', 'vmess', 'trojan', 'hysteria', 'tuic', 'anytls', 'naive', 'http'], true);
        if ($enableEch && ($security !== 'tls' || !$echCapable)) {
            return $this->fail([400, 'ECH 只能用于支持 TLS 的协议模板']);
        }

        $port = (int) ($params['port'] ?? 0);
        if ($port === 0) {
            $port = $this->allocateQuickPort();
        } elseif (Server::query()->where('server_port', $port)->orWhere('port', (string) $port)->exists()) {
            return $this->fail([400, '该端口已被面板中的其他节点使用']);
        }

        $tlsDomain = trim((string) ($params['tls_domain'] ?? ''));
        if ($security === 'tls' && $tlsDomain === '') {
            $tlsDomain = 'node.local';
        }

        $tlsSettings = null;
        $echMaterial = null;
        if ($security === 'tls') {
            $tlsSettings = [
                'server_name' => $tlsDomain,
                'allow_insecure' => true,
            ];
            if ($enableEch) {
                $echMaterial = $this->makeEchMaterial($tlsDomain);
                $tlsSettings['ech'] = [
                    'enabled' => true,
                    'key' => $echMaterial['key'],
                    'config' => $echMaterial['config'],
                    'query_server_name' => $tlsDomain,
                    'key_path' => null,
                    'config_path' => null,
                ];
            }
        }

        $realitySettings = null;
        $realityKeys = null;
        if ($security === 'reality') {
            $realityServerName = trim((string) ($params['reality_server_name'] ?? ''));
            if ($realityServerName === '') {
                return $this->fail([400, 'Reality 需要目标站域名/SNI']);
            }
            $realityPort = (int) ($params['reality_server_port'] ?? 443);
            $realityKeys = $this->makeRealityKeys();
            $realitySettings = [
                'server_name' => $realityServerName,
                'server_port' => $realityPort,
                'public_key' => $realityKeys['public_key'],
                'private_key' => $realityKeys['private_key'],
                'short_id' => $realityKeys['short_id'],
                'allow_insecure' => false,
            ];
        }

        $tlsMode = match ($security) {
            'tls' => 1,
            'reality' => 2,
            default => 0,
        };

        $protocolSettings = match ($type) {
            'mieru' => [
                'transport' => 'TCP',
                'traffic_pattern' => '',
                'multiplex' => ['enabled' => false],
            ],
            'shadowsocks' => [
                'cipher' => '2022-blake3-aes-128-gcm',
                'obfs' => null,
                'obfs_settings' => null,
                'plugin' => null,
                'plugin_opts' => null,
            ],
            'vless' => [
                'tls' => $tlsMode,
                'network' => 'tcp',
                'network_settings' => [],
                'tls_settings' => $tlsSettings ?? [],
                'reality_settings' => $realitySettings ?? [],
                'flow' => $security === 'reality' ? 'xtls-rprx-vision' : null,
                'encryption' => ['enabled' => false, 'encryption' => null, 'decryption' => null],
                'multiplex' => ['enabled' => false],
                'utls' => ['enabled' => false, 'fingerprint' => 'chrome'],
            ],
            'vmess' => [
                'tls' => $tlsMode,
                'network' => 'tcp',
                'network_settings' => [],
                'rules' => [],
                'tls_settings' => $tlsSettings ?? [],
                'multiplex' => ['enabled' => false],
                'utls' => ['enabled' => false, 'fingerprint' => 'chrome'],
            ],
            'trojan' => [
                'tls' => $tlsMode,
                'network' => 'tcp',
                'network_settings' => [],
                'tls_settings' => $tlsSettings ?? [],
                'reality_settings' => $realitySettings ?? [],
                'multiplex' => ['enabled' => false],
                'utls' => ['enabled' => false, 'fingerprint' => 'chrome'],
            ],
            'hysteria' => [
                'version' => 2,
                'bandwidth' => ['up' => null, 'down' => null],
                'obfs' => ['open' => false, 'type' => 'salamander', 'password' => null],
                'tls' => $tlsSettings,
                'hop_interval' => null,
            ],
            'tuic' => [
                'version' => 5,
                'congestion_control' => 'cubic',
                'alpn' => ['h3'],
                'udp_relay_mode' => 'native',
                'tls' => $tlsSettings,
            ],
            'anytls' => [
                'tls' => $tlsSettings,
                'padding_scheme' => [
                    'stop=8',
                    '0=30-30',
                    '1=100-400',
                    '2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000',
                    '3=9-9,500-1000',
                    '4=500-1000',
                    '5=500-1000',
                    '6=500-1000',
                    '7=500-1000',
                ],
            ],
            'socks' => [
                'tls' => 0,
                'tls_settings' => [],
            ],
            'naive' => [
                'tls' => 1,
                'tls_settings' => $tlsSettings,
            ],
            'http' => [
                'tls' => $tlsMode,
                'tls_settings' => $tlsSettings ?? [],
            ],
        };

        $payload = [
            'type' => $type,
            'name' => $params['name'],
            'host' => $params['host'],
            'port' => (string) $port,
            'server_port' => $port,
            'rate' => 1,
            'machine_id' => $machine->id,
            'group_ids' => array_values($params['group_ids'] ?? []),
            'route_ids' => array_values($params['route_ids'] ?? []),
            'tags' => ['quick-deploy'],
            'show' => (bool) ($params['show'] ?? true),
            'enabled' => true,
            'transfer_enable' => 0,
            'protocol_settings' => $protocolSettings,
        ];

        if ($security === 'tls') {
            // xboard-node expects literal "self" (not "self-signed").
            $payload['cert_config'] = [
                'cert_mode' => 'self',
                'domain' => $tlsDomain,
            ];
        }

        try {
            $server = Server::create($payload);
        } catch (Throwable $e) {
            Log::error($e);
            return $this->fail([500, '一键部署节点创建失败']);
        }

        return $this->success([
            'id' => $server->id,
            'name' => $server->name,
            'type' => $server->type,
            'security' => $security,
            'host' => $server->host,
            'port' => $server->port,
            'server_port' => $server->server_port,
            'machine_id' => $server->machine_id,
            'cert_mode' => data_get($server->cert_config, 'cert_mode'),
            'reality_public_key' => $realityKeys['public_key'] ?? null,
            'reality_short_id' => $realityKeys['short_id'] ?? null,
            'ech_config' => $echMaterial['config'] ?? null,
        ]);
    }

    private function makeRealityKeys(): array
    {
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        return [
            'private_key' => $this->base64UrlNoPad($privateKey),
            'public_key' => $this->base64UrlNoPad($publicKey),
            'short_id' => bin2hex(random_bytes(8)),
        ];
    }

    private function makeEchMaterial(string $publicName): array
    {
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);
        $configId = random_int(0, 255);

        $contents = '';
        $contents .= pack('C', $configId);
        $contents .= pack('n', 0x0020);
        $contents .= pack('n', 32) . $publicKey;
        $contents .= pack('n', 8);
        $contents .= pack('nn', 0x0001, 0x0001);
        $contents .= pack('nn', 0x0001, 0x0003);
        $contents .= pack('C', 0);
        $contents .= pack('C', strlen($publicName)) . $publicName;
        $contents .= pack('n', 0);

        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($contents)) . $contents;
        $echConfigList = pack('n', strlen($echConfig)) . $echConfig;
        $echKeysPayload = pack('n', 32) . $privateKey . pack('n', strlen($echConfig)) . $echConfig;

        return [
            'key' => "-----BEGIN ECH KEYS-----
"
                . chunk_split(base64_encode($echKeysPayload), 64, "
")
                . "-----END ECH KEYS-----",
            'config' => "-----BEGIN ECH CONFIGS-----
"
                . chunk_split(base64_encode($echConfigList), 64, "
")
                . "-----END ECH CONFIGS-----",
        ];
    }

    private function base64UrlNoPad(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function allocateQuickPort(): int
    {
        for ($i = 0; $i < 100; $i++) {
            $port = random_int(20000, 59999);
            $used = Server::query()
                ->where('server_port', $port)
                ->orWhere('port', (string) $port)
                ->exists();

            if (!$used) {
                return $port;
            }
        }

        throw new RuntimeException('无法分配可用端口');
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();
        if ($request->input('id')) {
            $server = Server::find($request->input('id'));
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }
            try {
                $server->update($params);
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        try {
            Server::create($params);
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'show' => 'nullable|integer',
            'machine_id' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        if (array_key_exists('show', $params)) {
            $server->show = (int) $params['show'];
        }
        if (array_key_exists('machine_id', $params)) {
            $server->machine_id = $params['machine_id'] ?: null;
        }
        if (array_key_exists('enabled', $params)) {
            $server->enabled = (bool) $params['enabled'];
        }

        if (!$server->save()) {
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    /**
     * 删除
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        if ($server->delete() === false) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }

    /**
     * 批量删除节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要删除的节点']);
        }

        try {
            $deleted = Server::whereIn('id', $ids)->delete();
            if ($deleted === false) {
                return $this->fail([500, '批量删除失败']);
            }
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量删除失败']);
        }
    }

    /**
     * 重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetTraffic(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        try {
            $server->u = 0;
            $server->d = 0;
            $server->save();
            
            Log::info("Server {$server->id} ({$server->name}) traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '重置失败']);
        }
    }

    /**
     * 批量重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchResetTraffic(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要重置的节点']);
        }

        try {
            Server::whereIn('id', $ids)->update([
                'u' => 0,
                'd' => 0,
            ]);
            
            Log::info("Servers " . implode(',', $ids) . " traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量重置失败']);
        }
    }

    /**
     * 批量更新节点属性（show等）
     */
    public function batchUpdate(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'show' => 'nullable|integer|in:0,1',
            'enabled' => 'nullable|boolean',
            'machine_id' => 'nullable|integer',
        ]);

        $ids = $params['ids'];
        if (empty($ids)) {
            return $this->fail([400, '请选择要更新的节点']);
        }

        $update = [];
        if (array_key_exists('show', $params) && $params['show'] !== null) {
            $update['show'] = (int) $params['show'];
        }
        if (array_key_exists('enabled', $params) && $params['enabled'] !== null) {
            $update['enabled'] = (bool) $params['enabled'];
        }
        if (array_key_exists('machine_id', $params)) {
            $update['machine_id'] = $params['machine_id'] ?: null;
        }

        if (empty($update)) {
            return $this->fail([400, '没有可更新的字段']);
        }

        try {
            $servers = Server::whereIn('id', $ids)->get();
            DB::transaction(function () use ($servers, $update) {
                /** @var Server $server */
                foreach ($servers as $server) {
                    $server->update($update);
                }
            });
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量更新失败']);
        }
    }

    /**
     * 复制节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $server = Server::find($request->input('id'));
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        $copiedServer = $server->replicate();
        $copiedServer->show = 0;
        $copiedServer->code = null;
        $copiedServer->u = 0;
        $copiedServer->d = 0;
        $copiedServer->save();

        return $this->success(true);
    }

    /**
     * Generate ECH (Encrypted Client Hello) key pair.
     * Returns PEM-encoded ECH key (server-side) and ECH config (client-side).
     */
    public function generateEchKey(Request $request)
    {
        $publicName = $request->input('public_name', 'ech.example.com');
        if (strlen($publicName) < 1 || strlen($publicName) > 253) {
            throw new ApiException('public_name must be a valid domain (1-253 bytes)');
        }

        // Generate X25519 key pair
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        // Build ECHConfigContents (draft-ietf-tls-esni-18)
        $contents = '';
        $contents .= pack('C', $configId);                // config_id
        $contents .= pack('n', 0x0020);                   // kem_id: DHKEM(X25519)
        $contents .= pack('n', 32) . $publicKey;          // public_key (length-prefixed)
        // cipher_suites: 2 suites × 4 bytes = 8 bytes
        $contents .= pack('n', 8);                        // cipher_suites byte length
        $contents .= pack('nn', 0x0001, 0x0001);          // HKDF-SHA256 + AES-128-GCM
        $contents .= pack('nn', 0x0001, 0x0003);          // HKDF-SHA256 + ChaCha20Poly1305
        $contents .= pack('C', 0);                        // max_name_length
        $contents .= pack('C', strlen($publicName)) . $publicName;
        $contents .= pack('n', 0);                        // extensions: empty

        // ECHConfig = version(2) + length(2) + contents
        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($contents)) . $contents;

        // ECHConfigList = total_length(2) + configs
        $echConfigList = pack('n', strlen($echConfig)) . $echConfig;

        // ECH Keys = private_key_len(2) + key(32) + config_len(2) + config
        $echKeysPayload = pack('n', 32) . $privateKey . pack('n', strlen($echConfig)) . $echConfig;

        $keyPem = "-----BEGIN ECH KEYS-----\n"
            . chunk_split(base64_encode($echKeysPayload), 64, "\n")
            . "-----END ECH KEYS-----";

        $configPem = "-----BEGIN ECH CONFIGS-----\n"
            . chunk_split(base64_encode($echConfigList), 64, "\n")
            . "-----END ECH CONFIGS-----";

        return $this->success([
            'key' => $keyPem,
            'config' => $configPem,
        ]);
    }
}
