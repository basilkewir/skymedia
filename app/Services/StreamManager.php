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

    /**
     * Start a channel:
     *  1. Ingest process:  source → HLS segments on disk (DVR recording)
     *  2. Push process:    DVR HLS → encode → RTMP/SRT output
     *
     * The push always reads from the local DVR HLS, so it is resilient to
     * brief ingest restarts without any playback gap.
     */
    public function startChannel(Channel $channel): bool
    {
        if (!$channel->is_active) {
            $channel->update(['is_active' => true]);
        }

        // Clean stop of any running processes
        $this->push->stop($channel);
        $this->ingest->stop($channel);

        $this->log($channel, 'info', 'stream_starting', 'Starting channel (ingest + push)', 'system');
        $channel->update([
            'stream_status' => 'starting',
            'push_status'   => 'connecting',
            'dvr_status'    => 'starting',
        ]);

        try {
            $dvrDir = $channel->dvr_directory;
            if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

            // Step 1 — start ingest (source → DVR segments)
            if (!$this->ingest->start($channel)) {
                throw new \RuntimeException('Ingest process failed to start — check ffmpeg log');
            }

            // Step 2 — start push (DVR HLS → output), waits for segments
            if (!$this->push->start($channel, waitForHls: true)) {
                // Non-fatal: push may start later via monitor
                $this->log($channel, 'warning', 'push_delayed', 'Push not ready yet — will retry on next monitor tick', 'push');
            }

            $fresh = $channel->fresh();
            $this->log($channel, 'info', 'stream_started',
                "Ingest PID {$fresh->pid} | Push PID {$fresh->push_pid}", 'source');

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
        $this->push->stop($channel);
        $this->ingest->stop($channel);

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

    /**
     * Source just went offline.
     * Stop ingest, keep push alive by switching it to DVR looping mode.
     */
    protected function onSourceLost(Channel $channel): void
    {
        $this->log($channel, 'warning', 'source_lost', 'Source went offline — switching to DVR playback', 'source');

        $this->ingest->stop($channel);
        $this->push->stop($channel);

        $channel->update([
            'source_live' => false,
            'pid'         => null,
            'push_pid'    => null,
            'dvr_status'  => 'idle',
        ]);

        if ($this->dvr->hasSegments($channel)) {
            $this->log($channel, 'info', 'failover_dvr', 'Failing over to DVR looping playback', 'push');

            if ($this->push->startDvrPlayback($channel)) {
                event(new StreamStatusChanged($channel, 'dvr_playback'));
            } else {
                $channel->update(['stream_status' => 'error', 'push_status' => 'error']);
                $this->log($channel, 'error', 'dvr_push_failed', 'DVR playback push failed to start', 'push');
            }
        } else {
            $this->log($channel, 'error', 'no_dvr', 'No DVR segments available — output idle', 'push');
            $channel->update(['stream_status' => 'offline', 'push_status' => 'idle']);
            event(new StreamStatusChanged($channel, 'offline'));
        }
    }

    /**
     * Source came back online.
     * Stop DVR loop, restart ingest + push in live mode.
     */
    protected function onSourceRecovered(Channel $channel): void
    {
        $this->log($channel, 'info', 'source_recovered', 'Source back online — resuming live ingest + push', 'source');

        $this->push->stop($channel);
        $this->ingest->stop($channel);

        $channel->update([
            'source_live'  => true,
            'dvr_pid'      => null,
            'dvr_status'   => 'starting',
            'last_live_at' => now(),
        ]);

        $this->startChannel($channel);
    }

    /**
     * Source is live and was already live on the last check.
     * Verify both processes are running; restart whichever has died.
     * Sync new DVR segments and enforce the rolling window.
     */
    protected function onSourceStillLive(Channel $channel): void
    {
        $ingestOk = $this->ingest->isRunning($channel);
        $pushOk   = $this->push->isRunning($channel);

        // Always sync and enforce the rolling window
        $this->dvr->syncSegments($channel);

        if ($ingestOk && $pushOk) {
            if ($channel->stream_status !== 'live') {
                $channel->update(['stream_status' => 'live', 'source_live' => true]);
            }
            $channel->resetRetries();
            return;
        }

        if (!$ingestOk && !$pushOk) {
            $this->log($channel, 'warning', 'both_died', 'Ingest + Push both dead — full restart', 'system');
            $this->startChannel($channel);
            return;
        }

        if (!$ingestOk) {
            $this->log($channel, 'warning', 'ingest_died', 'Ingest died — restarting ingest', 'source');
            $this->ingest->start($channel);
        }

        if (!$pushOk) {
            $this->log($channel, 'warning', 'push_died', 'Push died — restarting push', 'push');
            $this->push->start($channel, waitForHls: false);
        }
    }

    /**
     * Source is still offline.
     * Keep DVR loop running; refresh concat.txt periodically with any
     * segments that were recorded before the outage.
     */
    protected function onSourceStillDown(Channel $channel): void
    {
        $pushOk = $this->push->isRunning($channel);

        if ($pushOk) {
            // Periodically rebuild concat with latest segments
            if ($this->push->dvrPlaybackNeedsRefresh($channel)) {
                $this->log($channel, 'info', 'dvr_refresh', 'Refreshing DVR looping playlist', 'push');
                $this->push->stop($channel);

                if ($this->dvr->buildConcatFile($channel)) {
                    $this->push->startDvrPlayback($channel);
                }
            }

            if ($channel->stream_status !== 'dvr_playback') {
                $channel->update(['stream_status' => 'dvr_playback', 'push_status' => 'pushing']);
            }

            return;
        }

        // Push is not running — try to restart it
        if ($this->dvr->hasSegments($channel)) {
            $this->log($channel, 'warning', 'dvr_restart', 'DVR push not running — restarting', 'push');
            $this->push->startDvrPlayback($channel);
        } else {
            $maxed = $channel->incrementRetry('Source offline, no DVR segments');
            if ($maxed) {
                $channel->update(['stream_status' => 'error', 'push_status' => 'error']);
                $this->log($channel, 'critical', 'max_retries', "Max retries ({$channel->max_retries}) reached", 'system');
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
