<?php

namespace App\Services;

use App\Events\StreamStatusChanged;
use App\Models\Channel;
use App\Models\StreamLog;
use Illuminate\Support\Facades\Log;

/**
 * StreamManager orchestrates four independent modules:
 *
 *  1. INGEST     — manual. Operator starts/stops via UI.
 *                  source → HLS segments on disk (live.m3u8)
 *
 *  2. RECORDING  — automatic. Starts with ingest when record_duration > 0.
 *                  Records a single timed MP4 file. On success, atomically
 *                  replaces the previous recording. Daily re-record happens
 *                  automatically via the monitor.
 *
 *  3. PUSH       — manual OR automatic fallback.
 *                  Manual: operator picks live or DVR loop mode.
 *                  Auto-fallback: monitor switches push to loop the last
 *                  completed recording when the source goes offline.
 *                  Monitor switches back to live.m3u8 when source recovers.
 *
 *  4. DVR        — automatic. Segment rolling window synced by monitor
 *                  while ingest is running.
 */
class StreamManager
{
    public function __construct(
        protected FFmpegService  $ffmpeg,
        protected IngestService  $ingest,
        protected PushService    $push,
        protected DVRService     $dvr,
        protected RecordingService $recording,
    ) {}

    // ===================================================================
    //  INGEST — manual
    // ===================================================================

    public function startStream(Channel $channel): bool
    {
        $channel->update(['is_active' => true]);
        $this->log($channel, 'info', 'stream_starting', 'Starting ingest', 'source');
        $channel->update(['stream_status' => 'starting', 'dvr_status' => 'starting']);

        try {
            $dvrDir = $channel->dvr_directory;
            if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

            if (!$this->ingest->start($channel)) {
                throw new \RuntimeException('Ingest failed to start — check ffmpeg log');
            }

            // Start recording automatically if enabled
            if ($channel->fresh()->record_duration > 0) {
                $this->recording->start($channel->fresh());
            }

            $fresh = $channel->fresh();
            $this->log($channel, 'info', 'stream_started',
                "Ingest PID {$fresh->pid}" . ($fresh->record_pid ? " | Record PID {$fresh->record_pid}" : ''),
                'source');

            event(new StreamStatusChanged($channel, 'live'));
            return true;

        } catch (\Throwable $e) {
            $channel->incrementRetry($e->getMessage());
            $channel->update(['stream_status' => 'error', 'dvr_status' => 'error', 'source_live' => false]);
            $this->log($channel, 'error', 'stream_start_failed', $e->getMessage(), 'system');
            Log::error("[Channel {$channel->id}] startStream: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Stop ingest. Does NOT touch push.
     * Recording is also stopped (recording without ingest produces nothing useful).
     */
    public function stopStream(Channel $channel): bool
    {
        $this->recording->stop($channel);
        $this->ingest->stop($channel);

        $channel->update([
            'pid'           => null,
            'record_pid'    => null,
            'stream_status' => 'stopped',
            'dvr_status'    => 'idle',
            'record_status' => 'idle',
            'source_live'   => false,
            'is_active'     => false,
        ]);

        $this->log($channel, 'info', 'stream_stopped', 'Ingest stopped', 'source');
        event(new StreamStatusChanged($channel, 'stopped'));
        return true;
    }

    // ===================================================================
    //  PUSH — manual
    // ===================================================================

    public function startPush(Channel $channel, string $mode = 'live'): bool
    {
        $ok = match ($mode) {
            'dvr'      => $this->push->startDvrPlayback($channel),
            'fallback' => $this->push->startRecordingFallback($channel),
            default    => $this->push->startLive($channel),
        };

        if ($ok) {
            $this->log($channel, 'info', 'push_started',
                "Push started (mode: {$mode}) PID {$channel->fresh()->push_pid}", 'push');
        } else {
            $this->log($channel, 'error', 'push_failed',
                "Push failed to start (mode: {$mode})", 'push');
        }

        return $ok;
    }

    public function stopPush(Channel $channel): bool
    {
        $this->push->stop($channel);
        $this->log($channel, 'info', 'push_stopped', 'Push stopped manually', 'push');
        return true;
    }

    // ===================================================================
    //  LEGACY ALIASES
    // ===================================================================

    public function startChannel(Channel $channel): bool { return $this->startStream($channel); }
    public function stopChannel(Channel $channel): bool  { return $this->stopStream($channel); }

    // ===================================================================
    //  MONITOR — ingest + recording + auto-fallback push
    // ===================================================================

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

    public function activateAll(): void
    {
        Channel::where('is_active', true)->each(function (Channel $c) {
            if (in_array($c->stream_status, ['idle', 'stopped', 'error', 'offline'])) {
                $this->startStream($c);
            }
        });
    }

    // ===================================================================
    //  MONITOR STATE TRANSITIONS
    // ===================================================================

    /**
     * Source just went offline.
     * Stop ingest and recording. DVR segments stay on disk.
     * If push is running as 'live', automatically switch it to the
     * recording fallback file so the output never goes dark.
     */
    protected function onSourceLost(Channel $channel): void
    {
        $this->log($channel, 'warning', 'source_lost',
            'Source offline — stopping ingest, switching push to fallback', 'source');

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

        // ── Auto-fallback: keep push alive with the recording file ──
        $pushWasRunning = $this->push->isRunning($channel);

        if ($this->recording->hasFallback($channel)) {
            if ($pushWasRunning) {
                // Seamless switch: stop current push, start fallback
                $this->push->stop($channel->fresh());
            }
            if ($this->push->startRecordingFallback($channel->fresh())) {
                $this->log($channel, 'info', 'fallback_started',
                    "Push switched to recording fallback: {$channel->fallback_recording_path}", 'push');
                event(new StreamStatusChanged($channel, 'fallback'));
            } else {
                $this->log($channel, 'error', 'fallback_failed',
                    'Recording fallback push failed to start', 'push');
                event(new StreamStatusChanged($channel, 'offline'));
            }
        } else {
            // No recording fallback available — push stays in whatever state it was
            if (!$pushWasRunning) {
                $this->log($channel, 'warning', 'no_fallback',
                    'No recording fallback available and push not running — output offline', 'push');
            }
            event(new StreamStatusChanged($channel, 'offline'));
        }
    }

    /**
     * Source came back online.
     * Restart ingest + recording.
     * If push is running fallback, switch it back to live.m3u8.
     */
    protected function onSourceRecovered(Channel $channel): void
    {
        $this->log($channel, 'info', 'source_recovered',
            'Source back online — restarting ingest', 'source');

        $this->ingest->stop($channel);
        $channel->update(['source_live' => true, 'last_live_at' => now(), 'dvr_status' => 'starting']);

        $wasFallback = in_array($channel->stream_status, ['fallback', 'offline']);

        if (!$this->ingest->start($channel)) {
            $this->log($channel, 'error', 'ingest_restart_failed', 'Ingest restart failed', 'source');
            return;
        }

        // Start recording if enabled
        $fresh = $channel->fresh();
        if ($fresh->record_duration > 0 && !$this->recording->isRunning($fresh)) {
            $this->recording->start($fresh);
        }

        // If push was in fallback mode, seamlessly switch back to live
        if ($wasFallback && $this->push->isRunning($channel->fresh())) {
            // Wait briefly for HLS to be ready
            $waited = 0;
            while (!$this->ffmpeg->hlsReady($channel->fresh(), 2) && $waited < 8) {
                sleep(1);
                $waited++;
            }
            $this->push->stop($channel->fresh());
            if ($this->push->startLive($channel->fresh())) {
                $this->log($channel, 'info', 'push_switched_live',
                    'Push switched from fallback back to live source', 'push');
            }
        }

        $channel->update(['stream_status' => 'live']);
        event(new StreamStatusChanged($channel, 'live'));
    }

    /**
     * Source is live — maintain ingest, sync DVR, manage recording lifecycle.
     * Never touches push unless switching back from fallback.
     */
    protected function onSourceStillLive(Channel $channel): void
    {
        // ── Recording lifecycle ──────────────────────────────────────────
        if ($this->recording->justFinished($channel)) {
            // Recording process exited naturally (duration elapsed)
            $this->recording->finish($channel);
            $channel->refresh();

            // If push is in fallback mode and we just completed a fresh recording,
            // no need to switch — the fallback is still valid. Keep push as-is.
        }

        if ($this->recording->shouldRecord($channel)) {
            $this->log($channel, 'info', 'record_starting',
                "Starting {$channel->record_duration}s recording", 'source');
            $this->recording->start($channel);
        }

        // ── DVR sync ─────────────────────────────────────────────────────
        $this->dvr->syncSegments($channel);

        // ── Ingest watchdog ──────────────────────────────────────────────
        if (!$this->ingest->isRunning($channel)) {
            $this->log($channel, 'warning', 'ingest_died', 'Ingest died — restarting', 'source');
            $this->ingest->start($channel);
            // Also restart recording if it was running
            if ($channel->record_duration > 0) {
                $this->recording->start($channel->fresh());
            }
            return;
        }

        if ($channel->stream_status !== 'live') {
            $channel->update(['stream_status' => 'live', 'source_live' => true]);
        }

        $channel->resetRetries();
    }

    /**
     * Source still offline — check if recording just finished,
     * update fallback if so. Never restarts ingest.
     */
    protected function onSourceStillDown(Channel $channel): void
    {
        // Handle a recording that finished while source was offline
        // (shouldn't happen normally, but guard against edge cases)
        if ($this->recording->justFinished($channel)) {
            $this->recording->finish($channel);
        }

        if ($this->ingest->isRunning($channel)) {
            // Ingest alive but health says down — grace period
            return;
        }

        // Check if fallback push died and restart it
        if ($this->recording->hasFallback($channel) && !$this->push->isRunning($channel)) {
            $this->log($channel, 'warning', 'fallback_restart',
                'Fallback push died — restarting', 'push');
            $this->push->startRecordingFallback($channel);
            return;
        }

        $maxed = $channel->incrementRetry('Source offline');
        if ($maxed && $channel->stream_status !== 'error') {
            $channel->update(['stream_status' => 'error']);
            $this->log($channel, 'critical', 'max_retries',
                "Max retries ({$channel->max_retries}) reached", 'system');
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
