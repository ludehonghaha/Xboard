<?php

namespace App\Services\NoBrand;

final class NoBrandTrafficWatermark
{
    /**
     * Advance one absolute traffic counter safely.
     *
     * @return array{
     *   delta_u:int,
     *   delta_d:int,
     *   watermark:array{instance_id:string,upload_bytes:int,download_bytes:int,observed_at:int}
     * }
     */
    public static function advance(
        ?array $watermark,
        string $instanceId,
        int $uploadBytes,
        int $downloadBytes,
        int $observedAt
    ): array {
        $uploadBytes = max(0, $uploadBytes);
        $downloadBytes = max(0, $downloadBytes);
        $observedAt = max(0, $observedAt);

        if (
            !$watermark
            || (string) ($watermark['instance_id'] ?? '') !== $instanceId
        ) {
            return [
                'delta_u' => 0,
                'delta_d' => 0,
                'watermark' => [
                    'instance_id' => $instanceId,
                    'upload_bytes' => $uploadBytes,
                    'download_bytes' => $downloadBytes,
                    'observed_at' => $observedAt,
                ],
            ];
        }

        $previousUpload = max(0, (int) ($watermark['upload_bytes'] ?? 0));
        $previousDownload = max(0, (int) ($watermark['download_bytes'] ?? 0));
        $previousObservedAt = max(0, (int) ($watermark['observed_at'] ?? 0));

        $deltaU = $uploadBytes >= $previousUpload
            ? $uploadBytes - $previousUpload
            : 0;

        $deltaD = $downloadBytes >= $previousDownload
            ? $downloadBytes - $previousDownload
            : 0;

        return [
            'delta_u' => $deltaU,
            'delta_d' => $deltaD,
            'watermark' => [
                'instance_id' => $instanceId,
                // Never move a same-instance counter backwards. This makes
                // duplicate and out-of-order absolute reports idempotent.
                'upload_bytes' => max($previousUpload, $uploadBytes),
                'download_bytes' => max($previousDownload, $downloadBytes),
                'observed_at' => max($previousObservedAt, $observedAt),
            ],
        ];
    }
}
