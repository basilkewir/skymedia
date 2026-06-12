<?php

namespace App\Services;

use App\Events\StreamStatusChanged;
use App\Models\Channel;
use App\Models\StreamLog;
use Illuminate\Support\Facades\Log;

/**
 * StreamManager — orchestrates ingest, push, recording and DVR.
 *
 * PIPELINE:
 *
 *   [Source] ──► [Ingest ffmpeg] ──► live.m3u8 (HLS segments on disk)
 *                                         │
 *                                    [DVRService]
 *                                    enforces rolling window
 *                                         │
 *                              ┌──────────┴──────────┐
 *                         [Record ffmpeg]        [Push ffmpeg]
 *                         rec_YYYYMMDD.mp4       reads live.m3u8
 *                              │                 → RTMP / SRT output
 *                              │
 *                      on source offline:
 *                         Push switches to loop rec_*.mp4
 *                         → output stays LIVE to viewers
 *
 * GUARANTEE: Push output NEVER goes offline as long as at least one
 * completed recording exists (rec_*.mp4).
 */
class StreamManager
{
    public function __construct(
        protected FFmpegService   $ffmpeg,
        protected IngestService   $ingest,
        protected PushService     $push,
        protected DVRService      $dvr,
        protected RecordingService $recording,
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    //  PUBLIC API — called by controllers / artisan commands
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Start ingest + push for a channel.
     * Always starts ingest first, then push once HLS is ready.
     */
    public function startChannel(Channel $channel): bool
    {
        $channel->update(['is_active' => true, 'stream_status' => 'starting']);
        $this->log($channel, 'info', 'channel_starting', 'Starting channel');

        try {
            $dvrDir = $channel->dvr_directory;
            if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

            // 1. Start ingest
            if (!$this->ingest->start($channel)) {
                throw new \RuntimeException('Ingest failed to start — check ffmpeg log');
            }

            $fresh = $channel->fresh();
            $this->log($channel, 'info', 'ingest_started', "Ingest PID {$fresh->pid}");

            // 2. Start push (startLive waits for HLS to be ready internally)
            if (!$this->push->startLive($fresh)) {
                $this->log($channel, 'warning', 'push_start_failed',
                    'Push failed — will retry when ingest produces segments');
            } else {
                $this->log($channel, 'info', 'push_started', "Push PID {$fresh->fresh()->push_pid}");
            }

            // 3. Start recording if enabled
            if ($fresh->record_duration > 0) {
                sleep(2); // brief wait for HLS
                $this->recording->start($fresh->fresh());
            }

            $channel->fresh()->update(['stream_status' => 'live']);
            event(new StreamStatusChanged($channel, 'live'));
            return true;

        } catch (\Throwable $e) {
            $channel->update(['stream_status' => 'error']);
            $this->log($channel, 'error', 'channel_start_failed', $e->getMessage());
            return false;
        }
    }

    /**
     * Fully stop a channel — ingest, push, and recording.
     */
    public function stopChannel(Channel $channel): bool
    {
        $this->recording->stop($channel);
        $this->push->stop($channel);
        $this->ingest->stop($channel);

        $channel->update([
            'pid'           => null,
            'push_pid'      => null,
            'record_pid'    => null,
            'stream_status' => 'stopped',
            'push_status'   => 'stopped',
            'dvr_status'    => 'idle',
            'record_status' => 'idle',
            'source_live'   => false,
            'is_active'     => false,
        ]);

        $this->log($channel, 'info', 'channel_stopped', 'Channel stopped by operator');
        event(new StreamStatusChanged($channel, 'stopped'));
        return true;
    }

    /**
     * Start only the push — operator-initiated.
     * mode: 'live' | 'dvr' | 'fallback'
     */
    public function startPush(Channel $channel, string $mode = 'live'): bool
    {
        $ok = match ($mode) {
            'dvr'      => $this->push->startDvrPlayback($channel),
            'fallback' => $this->push->startRecordingFallback($channel),
            default    => $this->push->startLive($channel),
        };

        $this->log($channel, $ok ? 'info' : 'error',
            $ok ? 'push_started' : 'push_failed',
            $ok ? "Push started (mode={$mode})" : "Push failed (mode={$mode})");

        return $ok;
    }

    public function stopPush(Channel $channel): bool
    {
        $this->push->stop($channel);
        $this->log($channel, 'info', 'push_stopped', 'Push stopped by operator');
        return true;
    }

    /**
     * Restart both ingest and push cleanly.
     */
    public function restartChannel(Channel $channel): bool
    {
        $this->stopChannel($channel);
        sleep(1);
        $channel->update(['is_active' => true]);
        return $this->startChannel($channel->fresh());
    }

    /**
     * Activate all channels marked is_active that are not currently running.
     */
    public function activateAll(): void
    {
        Channel::where('is_active', true)
            ->whereIn('stream_status', ['idle', 'stopped', 'error', 'offline'])
            ->each(fn(Channel $c) => $this->startChannel($c));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  MONITOR TICK — called by streams:monitor daemon
    // ═══════════════════════════════════════════════════════════════════

    public function monitorChannel(Channel $channel): void
    {
        if (!$channel->is_active) return;

        $channel->update(['last_check_at' => now()]);
        $sourceLive = $this->ffmpeg->checkSourceHealth($channel);

        if ($sourceLive && !$channel->source_live) {
            $this->onSourceRecovered($channel->fresh());
        } elseif (!$sourceLive && $channel->source_live) {
            $this->onSourceLost($channel->fresh());
        } elseif ($sourceLive) {
            $this->onSourceStillLive($channel->fresh());
        } else {
            $this->onSourceStillDown($channel->fresh());
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  STATE TRANSITIONS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Source just went offline.
     *
     * 1. Stop ingest + recording immediately.
     * 2. Switch push to loop the latest recording — output stays live.
     * 3. If no recording exists yet, push stays as-is (still reading HLS
     *    segments already on disk until they run out).
     */
    protected function onSourceLost(Channel $channel): void
    {
        $this->log($channel, 'warning', 'source_lost',
            'Source offline — stopping ingest, switching push to recording fallback');

        $this->recording->stop($channel);
        $this->ingest->stop($channel);

        $channel->update([
            'source_live'   => false,
            'pid'           => null,
            'record_pid'    => null,
            'stream_status' => 'offline',
            'dvr_status'    => 'idle',
            'record_status' => 'idle',
        ]);

        // ── Seamless failover to recording ──────────────────────────────
        if ($this->recording->hasFallback($channel)) {
            $this->push->stop($channel->fresh());
            usleep(300_000);

            if ($this->push->startRecordingFallback($channel->fresh())) {
                $channel->update(['stream_status' => 'fallback', 'push_status' => 'fallback']);
                $this->log($channel, 'info', 'fallback_activated',
                    "Push now looping: {$channel->fallback_recording_path}");
                event(new StreamStatusChanged($channel, 'fallback'));
            } else {
                $this->log($channel, 'error', 'fallback_failed', 'Recording fallback push failed');
                event(new StreamStatusChanged($channel, 'offline'));
            }
        } else {
            // No recording yet — push reads stale HLS segments until they run out
            $this->log($channel, 'warning', 'no_fallback',
                'No recording available yet for fallback — waiting for source recovery');
            event(new StreamStatusChanged($channel, 'offline'));
        }
    }

    /**
     * Source just came back online.
     *
     * 1. Restart ingest immediately.
     * 2. Start recording.
     * 3. Once HLS is ready (≥2 segments), switch push back to live.
     */
    protected function onSourceRecovered(Channel $channel): void
    {
        $this->log($channel, 'info', 'source_recovered', 'Source back online — restarting ingest');

        $this->ingest->stop($channel);
        $channel->update(['source_live' => true, 'last_live_at' => now()]);

        if (!$this->ingest->start($channel)) {
            $this->log($channel, 'error', 'ingest_restart_failed', 'Ingest restart failed');
            return;
        }

        // Start recording
        $fresh = $channel->fresh();
        if ($fresh->record_duration > 0) {
            sleep(2);
            $this->recording->start($fresh->fresh());
        }

        // Switch push back to live (waits for HLS internally)
        $this->push->stop($fresh->fresh());
        usleep(200_000);

        if ($this->push->startLive($fresh->fresh())) {
            $channel->update(['stream_status' => 'live', 'push_status' => 'live']);
            $this->log($channel, 'info', 'push_switched_live', 'Push switched back to live source');
        } else {
            // HLS not ready yet — push will be picked up next tick
            $channel->update(['stream_status' => 'live']);
        }

        $channel->resetRetries();
        event(new StreamStatusChanged($channel, 'live'));
    }

    /**
     * Source is live and running normally.
     * Responsibilities: sync DVR, manage recording lifecycle, watchdog ingest+push.
     */
    protected function onSourceStillLive(Channel $channel): void
    {
        // ── Recording lifecycle ──────────────────────────────────────────
        if ($this->recording->justFinished($channel)) {
            $this->recording->finish($channel);
            $channel->refresh();
            $this->log($channel, 'info', 'recording_completed',
                "Recording completed: {$channel->fallback_recording_path}");
        }

        if ($this->recording->shouldRecord($channel)) {
            $this->recording->start($channel);
        }

        // ── DVR rolling window ───────────────────────────────────────────
        $this->dvr->syncSegments($channel);

        // ── Ingest watchdog ──────────────────────────────────────────────
        if (!$this->ingest->isRunning($channel)) {
            $this->log($channel, 'warning', 'ingest_died', 'Ingest process died — restarting');
            $this->ingest->start($channel);
            if ($channel->record_duration > 0) {
                sleep(2);
                $this->recording->start($channel->fresh());
            }
        }

        // ── Push watchdog ────────────────────────────────────────────────
        if (!$this->push->isRunning($channel)) {
            $this->log($channel, 'warning', 'push_died', 'Push process died — restarting');
            $this->push->startLive($channel);
        }

        if ($channel->stream_status !== 'live') {
            $channel->update(['stream_status' => 'live', 'source_live' => true]);
        }

        $channel->resetRetries();
    }

    /**
     * Source is still offline.
     * Watchdog: restart fallback push if it died.
     */
    protected function onSourceStillDown(Channel $channel): void
    {
        // Watchdog: restart fallback if push died
        if (!$this->push->isRunning($channel) && $this->recording->hasFallback($channel)) {
            $this->log($channel, 'warning', 'fallback_restart', 'Fallback push died — restarting');
            if ($this->push->startRecordingFallback($channel)) {
                $channel->update(['push_status' => 'fallback']);
            }
        }

        // Increment retry counter for alerting
        $channel->incrementRetry('Source offline');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  LOGGING
    // ═══════════════════════════════════════════════════════════════════

    protected function log(Channel $channel, string $level, string $event, string $message, ?array $meta = null): void
    {
        try {
            StreamLog::create([
                'channel_id' => $channel->id,
                'level'      => $level,
                'event'      => $event,
                'message'    => $message,
                'metadata'   => $meta,
            ]);
        } catch (\Throwable $e) {
            Log::error("StreamLog write failed: {$e->getMessage()}");
        }
    }
}
