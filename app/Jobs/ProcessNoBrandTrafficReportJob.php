<?php

namespace App\Jobs;

use App\Models\NoBrandTrafficReport;
use App\Models\NoBrandUserBinding;
use App\Models\Server;
use App\Services\NoBrand\NoBrandTrafficWatermark;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Utils\CacheKey;
use Throwable;

class ProcessNoBrandTrafficReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $reportId)
    {
        $this->onQueue('traffic_fetch');
    }

    public function handle(): void
    {
        $nodeType = null;
        $nodeId = null;
        $onlineUsers = 0;

        DB::transaction(function () use (&$nodeType, &$nodeId, &$onlineUsers) {
            $report = NoBrandTrafficReport::query()
                ->lockForUpdate()
                ->findOrFail($this->reportId);

            if ($report->status === 'processed') {
                return;
            }

            $server = Server::query()->findOrFail($report->server_id);
            if (
                (int) $server->machine_id !== (int) $report->machine_id
                || $server->runtime_driver !== 'nobrand'
                || $server->type !== Server::TYPE_MIERU
            ) {
                throw new \RuntimeException('NoBrand traffic report ownership mismatch');
            }

            $traffic = [];
            $deltaU = 0;
            $deltaD = 0;
            $seenUsers = [];

            foreach ((array) $report->readings as $reading) {
                if (!is_array($reading)) {
                    continue;
                }

                $userId = (int) ($reading['user_id'] ?? 0);
                $instanceId = (string) ($reading['instance_id'] ?? '');
                $upload = max(0, (int) ($reading['upload_bytes'] ?? 0));
                $download = max(0, (int) ($reading['download_bytes'] ?? 0));

                if ($userId <= 0 || $instanceId === '') {
                    continue;
                }

                /** @var NoBrandUserBinding|null $binding */
                $binding = NoBrandUserBinding::query()
                    ->where('server_id', $server->id)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();

                if (!$binding || !$binding->enabled || $binding->instance_id !== $instanceId) {
                    continue;
                }

                $meta = is_array($binding->runtime_meta) ? $binding->runtime_meta : [];
                $watermark = is_array($meta['traffic_watermark'] ?? null)
                    ? $meta['traffic_watermark']
                    : null;

                $advanced = NoBrandTrafficWatermark::advance(
                    $watermark,
                    $instanceId,
                    $upload,
                    $download,
                    (int) $report->observed_at
                );

                $meta['traffic_watermark'] = $advanced['watermark'];
                $binding->runtime_meta = $meta;
                $binding->save();

                $userDeltaU = (int) $advanced['delta_u'];
                $userDeltaD = (int) $advanced['delta_d'];

                if ($userDeltaU > 0 || $userDeltaD > 0) {
                    $traffic[$userId] = [$userDeltaU, $userDeltaD];
                    $deltaU += $userDeltaU;
                    $deltaD += $userDeltaD;
                }

                $seenUsers[$userId] = true;
            }

            if (!empty($traffic)) {
                $server->rate = $server->getCurrentRate();
                $serverData = $server->toArray();
                $protocol = $server->type;
                $timestamp = strtotime(date('Y-m-d'));

                // Execute the existing Xboard accounting jobs inside the same
                // database transaction. If any DB update fails, the traffic
                // watermark rolls back too, so the next report can retry the
                // same absolute counters without losing usage.
                (new TrafficFetchJob($serverData, $traffic, $protocol, $timestamp))->handle();
                (new StatUserJob($serverData, $traffic, $protocol, 'd'))->handle();
                (new StatServerJob($serverData, $traffic, $protocol, 'd'))->handle();
            }

            $report->status = 'processed';
            $report->delta_u = $deltaU;
            $report->delta_d = $deltaD;
            $report->processed_at = now()->timestamp;
            $report->error = null;
            $report->save();

            $nodeType = strtoupper($server->type);
            $nodeId = (int) $server->id;
            $onlineUsers = count($seenUsers);
        }, 3);

        if ($nodeType && $nodeId) {
            Cache::put(CacheKey::get("SERVER_{$nodeType}_LAST_PUSH_AT", $nodeId), time(), 3600);
            Cache::put(CacheKey::get("SERVER_{$nodeType}_ONLINE_USER", $nodeId), $onlineUsers, 3600);
        }
    }

    public function failed(?Throwable $exception): void
    {
        NoBrandTrafficReport::query()
            ->where('id', $this->reportId)
            ->where('status', '!=', 'processed')
            ->update([
                'status' => 'error',
                'error' => mb_substr((string) ($exception?->getMessage() ?? 'processing failed'), 0, 1000),
                'updated_at' => now(),
            ]);
    }
}
