<?php

namespace App\Services\NoBrand;

/**
 * Installer metadata for the external ike-sh/NoBrand-OneClick project.
 *
 * No upstream GPL source is copied into Xboard Lite. A NoBrand machine is an
 * independent machine type: Xboard only creates the machine record and emits
 * a checksum-pinned one-click bootstrap command. Protocol/user lifecycle stays
 * entirely inside NoBrand-OneClick.
 */
final class NoBrandInstaller
{
    public const DRIVER = 'nobrand-oneclick';
    public const UPSTREAM_REPOSITORY = 'ike-sh/NoBrand-OneClick';
    public const UPSTREAM_LICENSE = 'GPL-3.0';
    public const PINNED_VERSION = 'v3.2.2';
    public const INSTALLER_SHA256 = '37ba6fb4f35c7e032d05021782a090040af09337c5e95a42cbc0f8f0cf7d66c0';

    public static function installerUrl(): string
    {
        return sprintf(
            'https://github.com/%s/releases/download/%s/install-nobrand.sh',
            self::UPSTREAM_REPOSITORY,
            self::PINNED_VERSION
        );
    }

    public static function bootstrapCommand(): string
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
