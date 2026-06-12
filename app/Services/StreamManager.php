<?php

namespace App\Services;

use App\Events\StreamStatusChanged;
use App\Models\Channel;
use App\Models\StreamLog;
use Illuminate\Support\Facades\Log;

class StreamManager
{
    public function __construct(
        protected FFmpegService    $ffmpeg,
        protected IngestService    $ingest,
        protected PushService      $push,
        protected DVRService       $dvr,
        protected RecordingService $recording,
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    //  PUBLIC API
    // ═══════════════════════════════════════════════════════════════════

    public function startChannel(Channel $channel): bool
    {
        $channel->update(['is_active' => true, 'stream_status' => 'starting']);
        $this->log($channel, 'info', 'channel_starting', 'Starting channel');

        try {
            $dvrDir = $channel->dvr_directory;
            if (!is_dir($dvrDir)) {
                mkdir($dvrDir, 0755, true);
            }

            // 1. Start ingest — throws RuntimeException with ffmpeg stderr on failure
            $this->ingest->start($channel);
            $channel->refresh();
            $this->log($channel, 'info', 'ingest_started', "Ingest PID {$channel->pid}");

            // 2. Start push — waits internally for HLS segments (non-blocking on failure)
            if ($this->push->startLive($channel)) {
                $channel->refresh();
                $this->log($channel, 'info', 'push_started', "Push PID {$channel->push_pid}");
            } else {
                $this->log($channel, 'warning', 'push_start_failed',
                    'Push not ready yet — monitor will retry');
            }

            // 3. Recording is started by the monitor once segments exist
            //    (avoids blocking the web request with sleep())
            $channel->update(['stream_status' => 'live']);
            event(new StreamStatusChanged($channel, 'live'));
            return true;

        } catch (\Throwable $e) {
            $channel->update([
                'stream_status' => 'error',
                'last_error'    => substr($e->getMessage(), 0, 1000),
            ]);
            $this->log($channel, 'error', 'stream_start_failed', $e->getMessage());
            Log::error("[Channel {$channel->id}] startChannel: {$e->getMessage()}");
            return false;
        }
    }

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

    public function restartChannel(Channel $channel): bool
    {
        $this->stopChannel($channel);
        $channel->update(['is_active' => true]);
        return $this->startChannel($channel->fresh());
    }

    public function activateAll(): void
    {
        Channel::where('is_active', true)
            ->whereIn('stream_status', ['idle', 'stopped', 'error', 'offline'])
            ->each(fn(Channel $c) => $this->startChannel($c));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  MONITOR TICK
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

    protected function onSourceLost(Channel $channel): void
    {
        $this->log($channel, 'warning', 'source_lost',
            'Source offline — stopping ingest, switching push to fallback');

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

        if ($this->recording->hasFallback($channel)) {
            $this->push->stop($channel->fresh());

            if ($this->push->startRecordingFallback($channel->fresh())) {
                $channel->update(['stream_status' => 'fallback', 'push_status' => 'fallback']);
                $this->log($channel, 'info', 'fallback_activated',
                    "Push looping: {$channel->fallback_recording_path}");
                event(new StreamStatusChanged($channel, 'fallback'));
            } else {
                $this->log($channel, 'error', 'fallback_failed', 'Fallback push failed to start');
                event(new StreamStatusChanged($channel, 'offline'));
            }
        } else {
            $this->log($channel, 'warning', 'no_fallback',
                'No recording available yet — waiting for source recovery');
            event(new StreamStatusChanged($channel, 'offline'));
        }
    }

    protected function onSourceRecovered(Channel $channel): void
    {
        $this->log($channel, 'info', 'source_recovered', 'Source back online — restarting ingest');

        $this->ingest->stop($channel);
        $channel->update(['source_live' => true, 'last_live_at' => now()]);

        try {
            $this->ingest->start($channel);
        } catch (\Throwable $e) {
            $this->log($channel, 'error', 'ingest_restart_failed', $e->getMessage());
            return;
        }

        // Switch push back to live — push will start once HLS has segments
        $this->push->stop($channel->fresh());
        if ($this->push->startLive($channel->fresh())) {
            $channel->update(['stream_status' => 'live', 'push_status' => 'live']);
            $this->log($channel, 'info', 'push_switched_live', 'Push back to live');
        } else {
            $channel->update(['stream_status' => 'live']);
        }

        $channel->resetRetries();
        event(new StreamStatusChanged($channel, 'live'));
    }

    protected function onSourceStillLive(Channel $channel): void
    {
        // ── Recording lifecycle ──────────────────────────────────────────
        if ($this->recording->justFinished($channel)) {
            $this->recording->finish($channel);
            $channel->refresh();
            $this->log($channel, 'info', 'recording_completed',
                "Completed: {$channel->fallback_recording_path}");
        }

        // Update live filesize/duration for the recording-in-progress indicator
        $this->recording->refreshProgress($channel);

        // Start a new recording if none is running
        if ($this->recording->shouldRecord($channel)) {
            $started = $this->recording->start($channel);
            if ($started) {
                $this->log($channel, 'info', 'recording_started',
                    "Recording started ({$channel->record_duration}s)");
            }
        }

        // ── DVR rolling window ───────────────────────────────────────────
        $this->dvr->syncSegments($channel);

        // ── Ingest watchdog ──────────────────────────────────────────────
        if (!$this->ingest->isRunning($channel)) {
            $this->log($channel, 'warning', 'ingest_died', 'Ingest died — restarting');
            try {
                $this->ingest->start($channel);
            } catch (\Throwable $e) {
                $this->log($channel, 'error', 'ingest_restart_failed', $e->getMessage());
                return;
            }
        }

        // ── Push watchdog ────────────────────────────────────────────────
        if (!$this->push->isRunning($channel)) {
            $this->log($channel, 'warning', 'push_died', 'Push died — restarting');
            $ok = $this->push->startLive($channel);
            if ($ok) {
                $this->log($channel, 'info', 'push_restarted', 'Push restarted successfully');
                $channel->update(['push_status' => 'live']);
            } else {
                $this->log($channel, 'error', 'push_restart_failed', 'Push failed to restart — will retry next tick');
                $channel->update(['push_status' => 'error']);
            }
        }

        if ($channel->stream_status !== 'live') {
            $channel->update(['stream_status' => 'live', 'source_live' => true]);
        }

        // Update dvr_status to reflect recording is active
        $dvrStatus = $this->ingest->isRunning($channel) ? 'recording' : 'idle';
        if ($channel->dvr_status !== $dvrStatus) {
            $channel->update(['dvr_status' => $dvrStatus]);
        }

        $channel->resetRetries();
    }

    protected function onSourceStillDown(Channel $channel): void
    {
        if (!$this->push->isRunning($channel) && $this->recording->hasFallback($channel)) {
            $this->log($channel, 'warning', 'fallback_restart', 'Fallback push died — restarting');
            $ok = $this->push->startRecordingFallback($channel);
            if ($ok) {
                $channel->update(['push_status' => 'fallback']);
                $this->log($channel, 'info', 'fallback_restarted', 'Fallback push restarted');
            } else {
                $this->log($channel, 'error', 'fallback_restart_failed', 'Fallback push failed to restart');
            }
        }

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
            Log::error("StreamLog write: {$e->getMessage()}");
        }
    }
}
