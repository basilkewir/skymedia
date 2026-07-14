<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Channel;
use App\Services\IngestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Start the ffmpeg RTMP pull for a push-mode RTMP channel.
 *
 * Dispatched by RtmpController::onPublish immediately when the encoder
 * connects to MediaMTX. MediaMTX makes the stream available as
 * rtmp://mediamtx:1935/{key} the instant it receives the first data.
 */
class StartHlsPullIngest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 3;
    public int $maxExceptions = 3;

    public function __construct(
        private readonly int $channelId,
    ) {}

    public function handle(IngestService $ingest): void
    {
        $channel = Channel::find($this->channelId);

        if (! $channel || ! $channel->is_active) {
            Log::info("[RTMP] StartHlsPullIngest — channel {$this->channelId} not found or inactive, skipping");
            return;
        }

        // If ingest is already running (e.g. from a previous dispatch), skip.
        if ($ingest->isRunning($channel)) {
            Log::info("[RTMP] StartHlsPullIngest — {$channel->name} ingest already running, skipping");
            return;
        }

        // If the encoder disconnected while the job was queued and the channel
        // was manually stopped, skip.
        if ($channel->stream_status === 'stopped' && ! $channel->is_active) {
            Log::info("[RTMP] StartHlsPullIngest — {$channel->name} manually stopped, skipping");
            return;
        }

        try {
            Log::info("[RTMP] StartHlsPullIngest — {$channel->name} starting HLS pull (delayed job)");
            $ingest->startHlsPull($channel);
        } catch (\Throwable $e) {
            Log::error("[RTMP] StartHlsPullIngest — {$channel->name} failed: {$e->getMessage()}");
            throw $e;
        }
    }
}
