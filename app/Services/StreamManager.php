<?php

namespace App\Services;

use App\Events\StreamStatusChanged;
use App\Models\Channel;
use App\Models\StreamLog;
use Illuminate\Support\Facades\Log;

class StreamManager
{
    public function __construct(
        protected FFmpegService $ffmpeg,
        protected IngestService $ingest,
        protected PushService   $push,
        protected DVRService    $dvr,
    ) {}

    // ===================================================================
    //  PUBLIC API
    // ===================================================================

    public function startChannel(Channel $channel): bool
    {
        if (!$channel->is_active) {
            $channel->update(['is_active' => true]);
        }

        $this->push->stopAll($channel);
        $this->ingest->stop($channel);

        $this->log($channel, 'info', 'stream_starting', 'Starting channel', 'system');
        $channel->update([
            'stream_status' => 'starting',
            'push_status'   => 'connecting',
            'dvr_status'    => 'starting',
        ]);

        try {
            $dvrDir = $channel->dvr_directory;
            if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

            $ok = $this->ingest->start($channel);
            if (!$ok) {
                throw new \RuntimeException('Ingest failed to start (check ffmpeg log)');
            }

            $this->log($channel, 'info', 'stream_started', "Live+DVR+Push started via tee muxer (PID {$channel->fresh()->pid})", 'source');
            event(new StreamStatusChanged($channel, 'live'));
            return true;

        } catch (\Throwable $e) {
            $channel->incrementRetry($e->getMessage());
            $channel->update([
                'stream_status' => 'error',
                'push_status'   => 'error',
                'dvr_status'    => 'error',
                'source_live'   => false,
            ]);
            $this->log($channel, 'error', 'stream_start_failed', $e->getMessage(), 'system');
            Log::error("[Channel {$channel->id}] start failed: {$e->getMessage()}");
            return false;
        }
    }

    public function stopChannel(Channel $channel): bool
    {
        $this->ingest->stop($channel);
        $this->push->stopAll($channel);

        $channel->update([
            'pid'           => null,
            'push_pid'      => null,
            'dvr_pid'       => null,
            'stream_status' => 'stopped',
            'push_status'   => 'idle',
            'dvr_status'    => 'idle',
            'is_active'     => false,
            'source_live'   => false,
        ]);

        $this->log($channel, 'info', 'stream_stopped', 'Channel stopped', 'system');
        event(new StreamStatusChanged($channel, 'stopped'));
        return true;
    }

    // ===================================================================
    //  MONITORING
    // ===================================================================

    public function monitorChannel(Channel $channel): void
    {
        if (!$channel->is_active) return;

        $channel->update(['last_check_at' => now()]);
        $sourceLive = $this->ffmpeg->checkSourceHealth($channel);

        if ($sourceLive && !$channel->source_live) {
            $this->onSourceRecovered($channel);
        } elseif (!$sourceLive && $channel->source_live) {
            $this->onSourceLost($channel);
        } elseif ($sourceLive) {
            $this->onSourceStillLive($channel);
        } else {
            $this->onSourceStillDown($channel);
        }
    }

    public function activateAll(): void
    {
        Channel::where('is_active', true)->each(function (Channel $c) {
            if (in_array($c->stream_status, ['idle', 'stopped', 'error'])) {
                $this->startChannel($c);
            }
        });
    }

    // ===================================================================
    //  STATE TRANSITIONS
    // ===================================================================

    protected function onSourceLost(Channel $channel): void
    {
        $this->log($channel, 'warning', 'source_lost', 'Source went offline', 'source');

        $this->ingest->stop($channel);
        $this->push->stopLive($channel);

        $channel->update([
            'source_live' => false,
            'pid'         => null,
            'push_pid'    => null,
            'dvr_status'  => 'idle',
        ]);

        if ($this->dvr->hasSegments($channel)) {
            $this->log($channel, 'info', 'failover_dvr', 'Failing over to DVR playback', 'push');

            if ($this->push->startDvrPlayback($channel)) {
                event(new StreamStatusChanged($channel, 'dvr_playback'));
            }
        } else {
            $this->log($channel, 'error', 'no_dvr', 'No DVR segments — push idle', 'push');
            $channel->update(['stream_status' => 'offline', 'push_status' => 'idle']);
            event(new StreamStatusChanged($channel, 'offline'));
        }
    }

    protected function onSourceRecovered(Channel $channel): void
    {
        $this->log($channel, 'info', 'source_recovered', 'Source back online', 'source');

        $this->push->stopDvrPlayback($channel);
        $this->ingest->stop($channel);

        $channel->update([
            'source_live'  => true,
            'dvr_pid'      => null,
            'dvr_status'   => 'starting',
            'last_live_at' => now(),
        ]);

        $this->startChannel($channel);
    }

    protected function onSourceStillLive(Channel $channel): void
    {
        $ingestOk = $this->ingest->isRunning($channel);
        $pushOk   = $this->push->isLiveRunning($channel);

        if ($ingestOk && $pushOk) {
            $this->dvr->syncSegments($channel);

            if ($channel->stream_status !== 'live') {
                $channel->update(['stream_status' => 'live', 'source_live' => true]);
            }
            $channel->resetRetries();

        } elseif (!$ingestOk && !$pushOk) {
            $this->log($channel, 'warning', 'both_died', 'Ingest + Push both dead — restarting', 'system');
            $this->startChannel($channel);

        } elseif (!$ingestOk) {
            $this->log($channel, 'warning', 'ingest_died', 'Ingest module died — restarting', 'source');
            $this->ingest->stop($channel);
            $this->ingest->start($channel);

        } elseif (!$pushOk) {
            $this->log($channel, 'warning', 'push_died', 'Push module died — restarting', 'push');
            $this->push->stopLive($channel);
            $this->push->startLive($channel);
        }
    }

    protected function onSourceStillDown(Channel $channel): void
    {
        $dvrOk = $this->push->isDvrRunning($channel);

        if ($dvrOk) {
            $this->dvr->syncSegments($channel);

            if ($this->push->dvrPlaybackNeedsRestart($channel)) {
                $this->log($channel, 'info', 'dvr_refresh', 'Refreshing DVR playback with new segments', 'push');
                $this->push->stopDvrPlayback($channel);

                if ($this->dvr->buildConcatFile($channel)) {
                    $this->push->startDvrPlayback($channel);
                }
            }

            if ($channel->stream_status !== 'dvr_playback') {
                $channel->update(['stream_status' => 'dvr_playback', 'push_status' => 'pushing']);
            }

        } else {
            if ($this->dvr->hasSegments($channel)) {
                $this->log($channel, 'warning', 'dvr_restart', 'DVR playback not running — starting', 'push');
                if ($this->dvr->buildConcatFile($channel)) {
                    $this->push->startDvrPlayback($channel);
                }
            } else {
                $maxed = $channel->incrementRetry('Source offline, no DVR');
                if ($maxed) {
                    $channel->update(['stream_status' => 'error', 'push_status' => 'error']);
                    $this->log($channel, 'critical', 'max_retries', "Max retries ({$channel->max_retries}) reached", 'system');
                }
            }
        }
    }

    // ===================================================================
    //  LOGGING
    // ===================================================================

    protected function log(Channel $channel, string $level, string $event, string $message, string $category = 'system', ?array $meta = null): void
    {
        try {
            StreamLog::create([
                'channel_id' => $channel->id,
                'level'      => $level,
                'event'      => $event,
                'message'    => $message,
                'metadata'   => array_merge(['category' => $category], $meta ?? []),
            ]);
        } catch (\Throwable $e) {
            Log::error("StreamLog write failed: {$e->getMessage()}");
        }
    }
}
