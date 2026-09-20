<?php

require_once __DIR__ . '/../../app/Services/NoBrand/NoBrandTrafficWatermark.php';

use App\Services\NoBrand\NoBrandTrafficWatermark;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
};

$first = NoBrandTrafficWatermark::advance(null, 'u1111111111111111', 100, 200, 1000);
$assert($first['delta_u'] === 0 && $first['delta_d'] === 0, 'first observation is baseline only');
$assert($first['watermark']['upload_bytes'] === 100, 'baseline upload stored');
$assert($first['watermark']['download_bytes'] === 200, 'baseline download stored');

$normal = NoBrandTrafficWatermark::advance(
    $first['watermark'],
    'u1111111111111111',
    150,
    280,
    1030
);
$assert($normal['delta_u'] === 50 && $normal['delta_d'] === 80, 'normal increments are counted');

$duplicate = NoBrandTrafficWatermark::advance(
    $normal['watermark'],
    'u1111111111111111',
    150,
    280,
    1040
);
$assert($duplicate['delta_u'] === 0 && $duplicate['delta_d'] === 0, 'duplicate absolute report is idempotent');

$outOfOrder = NoBrandTrafficWatermark::advance(
    $normal['watermark'],
    'u1111111111111111',
    140,
    270,
    1020
);
$assert($outOfOrder['delta_u'] === 0 && $outOfOrder['delta_d'] === 0, 'out-of-order report is ignored');
$assert($outOfOrder['watermark']['upload_bytes'] === 150, 'out-of-order upload cannot rewind watermark');
$assert($outOfOrder['watermark']['download_bytes'] === 280, 'out-of-order download cannot rewind watermark');
$assert($outOfOrder['watermark']['observed_at'] === 1030, 'out-of-order time cannot rewind watermark');

$recreated = NoBrandTrafficWatermark::advance(
    $normal['watermark'],
    'u2222222222222222',
    7,
    9,
    1100
);
$assert($recreated['delta_u'] === 0 && $recreated['delta_d'] === 0, 'new isolated instance re-baselines');
$assert($recreated['watermark']['instance_id'] === 'u2222222222222222', 'new instance identity stored');
$assert($recreated['watermark']['upload_bytes'] === 7, 'new instance upload baseline stored');
$assert($recreated['watermark']['download_bytes'] === 9, 'new instance download baseline stored');

$mixed = NoBrandTrafficWatermark::advance(
    $normal['watermark'],
    'u1111111111111111',
    175,
    260,
    1060
);
$assert($mixed['delta_u'] === 25 && $mixed['delta_d'] === 0, 'one advancing counter can be counted independently');
$assert($mixed['watermark']['upload_bytes'] === 175, 'advancing counter moves forward');
$assert($mixed['watermark']['download_bytes'] === 280, 'regressing counter stays pinned');

fwrite(STDOUT, "[PASS] NoBrand traffic watermark smoke tests\n");
