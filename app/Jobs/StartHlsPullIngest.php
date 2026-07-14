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
 * Start the ffmpeg HLS pull for a push-mode RTMP channel.
 *
 * Dispatched by RtmpController::onPublish with a 3-second delay so
 * nginx-rtop has time to start writing HLS segments before ffmpeg
 * tries to pull them.
 */
class StartHlsPullIngest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 5;
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
