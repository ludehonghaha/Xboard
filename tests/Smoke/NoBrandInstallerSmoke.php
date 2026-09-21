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
$assert(NoBrandInstaller::POLICY_AGENT_VERSION === '0.1.0', 'policy agent version');
$assert(
    NoBrandInstaller::INSTALLER_SHA256 === '37ba6fb4f35c7e032d05021782a090040af09337c5e95a42cbc0f8f0cf7d66c0',
    'pinned installer digest'
);

$url = NoBrandInstaller::installerUrl();
$command = NoBrandInstaller::bootstrapCommand(
    'https://panel.example.com/',
    7,
    'machine-token'
);

$assert(str_contains($url, '/releases/download/v3.2.2/install-nobrand.sh'), 'exact release URL');
$assert(str_contains($command, 'sha256sum -c -'), 'checksum verification is present');
$assert(str_contains($command, 'manager install'), 'manager install is present');
$assert(str_contains($command, 'nobrand --version'), 'post-install verification is present');
$assert(str_contains($command, NoBrandInstaller::POLICY_INSTALLER_URL), 'policy installer is chained');
$assert(str_contains($command, '--machine-id 7'), 'policy agent receives machine id');
$assert(str_contains($command, '--panel'), 'policy agent receives panel URL');
$assert(str_contains($command, '--token'), 'policy agent receives machine token');

$assert(!str_contains($command, 'xboard-node'), 'NoBrand bootstrap does not install Xboard-Node');
$assert(!str_contains($command, 'mieru install'), 'bootstrap does not deploy Mieru');
$assert(!str_contains($command, 'user-add'), 'bootstrap does not create NoBrand users');
$assert(!str_contains($command, 'user-del'), 'bootstrap does not delete NoBrand users');

fwrite(STDOUT, "[PASS] standalone NoBrand machine + policy bridge installer smoke test\n");
