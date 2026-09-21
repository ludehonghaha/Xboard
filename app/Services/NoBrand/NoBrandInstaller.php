<?php

namespace App\Services\NoBrand;

/**
 * Installer metadata for the external ike-sh/NoBrand-OneClick project.
 *
 * A NoBrand machine remains an independent protocol runtime. Xboard installs
 * only a narrow policy bridge that can synchronize existing Mieru users'
 * quota, bandwidth, expiry and enabled state.
 */
final class NoBrandInstaller
{
    public const DRIVER = 'nobrand-oneclick';
    public const UPSTREAM_REPOSITORY = 'ike-sh/NoBrand-OneClick';
    public const UPSTREAM_LICENSE = 'GPL-3.0';
    public const PINNED_VERSION = 'v3.2.2';
    public const INSTALLER_SHA256 = '37ba6fb4f35c7e032d05021782a090040af09337c5e95a42cbc0f8f0cf7d66c0';

    public const POLICY_AGENT_VERSION = '0.1.0';
    public const POLICY_INSTALLER_URL = 'https://raw.githubusercontent.com/ludehonghaha/Xboard/xboard-lite-v1/agents/nobrand-policy/install.sh';

    public static function installerUrl(): string
    {
        return sprintf(
            'https://github.com/%s/releases/download/%s/install-nobrand.sh',
            self::UPSTREAM_REPOSITORY,
            self::PINNED_VERSION
        );
    }

    public static function bootstrapCommand(string $panelUrl, int $machineId, string $token): string
    {
        $url = escapeshellarg(self::installerUrl());
        $sha = escapeshellarg(self::INSTALLER_SHA256);

        $manager = implode(' && ', [
            'set -e',
            'tmp="$(mktemp /tmp/xboard-nobrand.XXXXXX.sh)"',
            'curl -fsSL ' . $url . ' -o "$tmp"',
            'printf \'%s  %s\\n\' ' . $sha . ' "$tmp" | sha256sum -c -',
            'sudo bash "$tmp" manager install',
            'rm -f "$tmp"',
            'sudo nobrand --version',
        ]);

        $policy = sprintf(
            'curl -fsSL %s | sudo bash -s -- --panel %s --machine-id %d --token %s',
            escapeshellarg(self::POLICY_INSTALLER_URL),
            escapeshellarg(rtrim($panelUrl, '/')),
            $machineId,
            escapeshellarg($token)
        );

        return $manager . ' && ' . $policy;
    }
}
