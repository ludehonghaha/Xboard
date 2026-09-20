<?php

namespace App\Services\NoBrand;

use InvalidArgumentException;

/**
 * Xboard Lite adapter contract for ike-sh/NoBrand-OneClick.
 *
 * No upstream GPL source is vendored here. This class only defines the
 * pinned external artifact, integrity check and the structured actions
 * executed by the Xboard-owned local companion.
 */
final class NoBrandDriver
{
    public const DRIVER = 'nobrand-hybrid';
    public const UPSTREAM_REPOSITORY = 'ike-sh/NoBrand-OneClick';
    public const UPSTREAM_LICENSE = 'GPL-3.0';
    public const PINNED_VERSION = 'v3.2.2';
    public const INSTALLER_SHA256 = '37ba6fb4f35c7e032d05021782a090040af09337c5e95a42cbc0f8f0cf7d66c0';

    public const RUNTIME_NATIVE = 'native';
    public const RUNTIME_NOBRAND = 'nobrand';

    /**
     * Capabilities present in the pinned upstream NoBrand release.
     * Not every item is exposed as an Xboard node type yet.
     */
    public const UPSTREAM_PROTOCOLS = [
        'mieru',
        'snell',
        'hysteria2',
        'tuic',
        'vless-sudoku',
        'vless-reality',
        'ssh-tunnel',
        'forward',
    ];

    /**
     * Xboard node types currently implemented by the NoBrand companion.
     * Snell / Sudoku / SSH / Forward need dedicated panel modelling later.
     */
    public const PANEL_RUNTIME_TYPES = [
        'mieru',
    ];

    public const COMPANION_VERSION = '0.3.0';
    public const COMPANION_INSTALLER_URL = 'https://raw.githubusercontent.com/ludehonghaha/Xboard/xboard-lite-v1/agents/nobrand/install.sh';

    /**
     * Structured allow-list. The agent must map these actions to fixed CLI
     * templates. Arbitrary shell input from the panel is intentionally absent.
     */
    public const ACTIONS = [
        'manager.install',
        'manager.status',
        'manager.doctor',
        'nodes.list',
        'ingress.list',
        'ingress.show',
        'mieru.install',
        'mieru.reconfigure',
        'mieru.status',
        'mieru.user-add',
        'mieru.user-del',
        'mieru.user-show',
        'mieru.user-set-quota',
        'mieru.user-set-expire',
        'mieru.user-set-rate',
        'snell.install',
        'snell.show',
        'snell.status',
        'hy2.install',
        'hy2.show',
        'hy2.status',
        'tuic.install',
        'tuic.show',
        'tuic.status',
        'vless-sudoku.install',
        'vless-sudoku.show',
        'vless-sudoku.status',
        'vless-reality.install',
        'vless-reality.show',
        'vless-reality.status',
        'ssh.install',
        'ssh.show',
        'ssh.status',
        'forward.list',
        'forward.show',
    ];

    public static function installerUrl(): string
    {
        return sprintf(
            'https://github.com/%s/releases/download/%s/install-nobrand.sh',
            self::UPSTREAM_REPOSITORY,
            self::PINNED_VERSION
        );
    }

    public static function capabilities(): array
    {
        return [
            'driver' => self::DRIVER,
            'upstream_repository' => self::UPSTREAM_REPOSITORY,
            'upstream_license' => self::UPSTREAM_LICENSE,
            'version' => self::PINNED_VERSION,
            'installer_sha256' => self::INSTALLER_SHA256,
            'upstream_protocols' => self::UPSTREAM_PROTOCOLS,
            'panel_runtime_types' => self::PANEL_RUNTIME_TYPES,
            'actions' => self::ACTIONS,
            'vendored' => false,
            'execution_model' => 'structured-local-agent-actions',
            'companion_required' => true,
            'companion_implemented' => true,
            'companion_version' => self::COMPANION_VERSION,
            'implemented_runtime_protocols' => self::PANEL_RUNTIME_TYPES,
            'phase' => 3,
        ];
    }

    public static function assertActionAllowed(string $action): void
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException("Unsupported NoBrand action: {$action}");
        }
    }

    /**
     * Exact, checksum-pinned manager bootstrap.
     * The caller should run this locally on the target machine as root.
     */
    public static function companionBootstrapCommand(string $panelUrl, int $machineId, string $token): string
    {
        if ($machineId <= 0) {
            throw new InvalidArgumentException('machineId must be positive');
        }

        return sprintf(
            'curl -fsSL %s | sudo bash -s -- --panel %s --machine-id %d --token %s',
            escapeshellarg(self::COMPANION_INSTALLER_URL),
            escapeshellarg(rtrim($panelUrl, '/')),
            $machineId,
            escapeshellarg($token)
        );
    }

    public static function managerBootstrapCommand(): string
    {
        $url = escapeshellarg(self::installerUrl());
        $sha = escapeshellarg(self::INSTALLER_SHA256);

        return implode(' && ', [
            'set -e',
            'tmp="$(mktemp /tmp/xboard-nobrand.XXXXXX.sh)"',
            'curl -fsSL ' . $url . ' -o "$tmp"',
            'printf \'%s  %s\\n\' ' . $sha . ' "$tmp" | sha256sum -c -',
            'sudo bash "$tmp" manager install',
            'rm -f "$tmp"',
            'sudo nobrand --version',
        ]);
    }
}
