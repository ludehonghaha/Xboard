<?php

require_once __DIR__ . '/../../app/Services/NoBrand/NoBrandInstaller.php';

use App\Services\NoBrand\NoBrandInstaller;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
};

$assert(NoBrandInstaller::DRIVER === 'nobrand-oneclick', 'driver name');
$assert(NoBrandInstaller::PINNED_VERSION === 'v3.2.2', 'pinned version');
$assert(
    NoBrandInstaller::INSTALLER_SHA256 === '37ba6fb4f35c7e032d05021782a090040af09337c5e95a42cbc0f8f0cf7d66c0',
    'pinned installer digest'
);

$url = NoBrandInstaller::installerUrl();
$command = NoBrandInstaller::bootstrapCommand();

$assert(str_contains($url, '/releases/download/v3.2.2/install-nobrand.sh'), 'exact release URL');
$assert(str_contains($command, 'sha256sum -c -'), 'checksum verification is present');
$assert(str_contains($command, 'manager install'), 'manager install is present');
$assert(str_contains($command, 'nobrand --version'), 'post-install verification is present');
$assert(!str_contains($command, 'xboard-node'), 'NoBrand bootstrap does not install Xboard-Node');
$assert(!str_contains($command, 'machine-id'), 'NoBrand bootstrap is not panel-agent coupled');
$assert(!str_contains($command, 'companion'), 'NoBrand bootstrap has no companion agent');

fwrite(STDOUT, "[PASS] standalone NoBrand installer smoke test\n");
