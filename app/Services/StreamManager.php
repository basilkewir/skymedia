<?php

declare(strict_types=1);

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
        protected PlayoutService   $playout,
        protected PushService      $push,
        protected DVRService       $dvr,
        protected RecordingService $recording,
        protected AlertService     $alert,
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

            // 1. Start ingest — source → HLS segments → live.m3u8
            $this->ingest->start($channel);
            $channel->refresh();
            $this->log($channel, 'info', 'ingest_started', "Ingest PID {$channel->pid}");

            // 2. Mark playout as live — no process needed, push reads live.m3u8 directly
            $this->playout->switchToLive($channel);

            // 3. Start push immediately if playlist is ready — monitor will retry if not
            $this->startPushIfReady($channel);

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

    private function startPushIfReady(Channel $channel): void
    {
        if (empty($channel->push_url)) return;

        // The playlist may not exist yet (ingest just started) — the monitor
        // will pick it up on the next tick. We try now as a fast path.
        $playlist = $this->playout->outputPlaylist($channel);
        if (!file_exists($playlist)) return;

        if ($this->ffmpeg->hlsReady($channel, 2)) {
            $this->push->start($channel);
            $this->log($channel, 'info', 'push_started', 'Push started');
        }
    }

    public function stopChannel(Channel $channel): bool
    {
        $this->recording->stop($channel);
        $this->push->stop($channel);
        $this->playout->stop($channel);
        $this->ingest->stop($channel);

        $channel->update([
            'pid'            => null,
            'playout_pid'    => null,
            'push_pid'       => null,
            'record_pid'     => null,
            'stream_status'  => 'stopped',
            'playout_status' => 'stopped',
            'push_status'    => 'stopped',
            'dvr_status'     => 'idle',
            'record_status'  => 'idle',
            'source_live'    => false,
            'is_active'      => false,
        ]);

        $this->log($channel, 'info', 'channel_stopped', 'Channel stopped by operator');
        event(new StreamStatusChanged($channel, 'stopped'));
        return true;
    }

    public function startPush(Channel $channel): bool
    {
        $ok = $this->push->start($channel);
        if ($ok) {
            $this->log($channel, 'info', 'push_started', 'Push started');
        } else {
            $ch = $channel->fresh();
            $reason = $ch->last_error ?: 'Unknown — check push log for details';
            $this->log($channel, 'error', 'push_failed', "Push failed: {$reason}");
        }
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
            'Source offline — stopping ingest, switching playout to fallback');

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

        if ($this->playout->hasFallback($channel)) {
            // Start fallback loop → symlinks output.m3u8 → playout.m3u8
            // Push keeps running — ffmpeg picks up new playlist on next refresh.
            if ($this->playout->switchToFallback($channel->fresh())) {
                $channel->update(['stream_status' => 'fallback', 'playout_status' => 'fallback']);
                $this->log($channel, 'info', 'fallback_activated',
                    'Playout on fallback — push continues uninterrupted via output.m3u8 symlink');
                $this->alert->sendOfflineAlert($channel->fresh(), 'Source unreachable', true);
                event(new StreamStatusChanged($channel, 'fallback'));
            } else {
                $this->log($channel, 'error', 'fallback_failed', 'Fallback playout failed to start');
                $this->alert->sendOfflineAlert($channel->fresh(), 'Fallback playout failed', false);
                event(new StreamStatusChanged($channel, 'offline'));
            }
        } else {
            // No fallback file yet — keep push running. The output.m3u8 symlink
            // still points to live.m3u8 which has the last segments. Push will
            // stall on the stale playlist until source recovers or a recording
            // completes and fallback becomes available.
            $this->log($channel, 'warning', 'no_fallback',
                'No recording available — push continues on stale live playlist');
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

        // Wait for live.m3u8 to have segments, then swap the symlink.
        // Push keeps running — ffmpeg picks up live.m3u8 on next refresh.
        $start = time();
        while (time() - $start < 10) {
            if ($this->ffmpeg->hlsReady($channel->fresh(), 2)) break;
            usleep(250_000);
        }

        // Atomically symlink output.m3u8 → live.m3u8, stop fallback loop
        $this->playout->switchToLive($channel->fresh());

        $channel->update(['stream_status' => 'live', 'playout_status' => 'live']);
        $channel->resetRetries();
        $this->alert->sendRecoveryAlert($channel->fresh());
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

        $this->recording->refreshProgress($channel);

        if ($this->recording->shouldRecord($channel)) {
            if ($this->recording->start($channel)) {
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

        // ── Playout: in live mode there is no process — nothing to watch ─
        // If somehow playout_status got set to fallback while source is live, fix it
        if ($channel->playout_status === 'fallback') {
            $this->playout->switchToLive($channel);
            $channel->update(['playout_status' => 'live']);
        }

        // ── Push watchdog ────────────────────────────────────────────────
        // Push reads output.m3u8 (stable path). It only needs restart
        // if the ffmpeg process has died unexpectedly. Retry throttle
        // prevents burning CPU on a bad RTMP connection.
        if (!$this->push->isRunning($channel)) {
            $playlist = $this->playout->outputPlaylist($channel);
            if (file_exists($playlist) && $this->ffmpeg->hlsReady($channel, 2)) {
                $wasPreviouslyLive = $channel->push_status === 'live';
                if ($channel->retry_count > 0 && $channel->retry_count % 3 === 0) {
                    // Log but don't spam — restart every 3rd tick
                }
                $this->log($channel, 'warning',
                    $wasPreviouslyLive ? 'push_died' : 'push_not_running',
                    $wasPreviouslyLive ? 'Push died — restarting' : 'Push not running — starting');
                if ($this->push->start($channel)) {
                    $channel->update(['push_status' => 'live']);
                    $channel->resetRetries();
                    $this->log($channel, 'info', 'push_started',
                        $wasPreviouslyLive ? 'Push restarted' : 'Push started');
                } else {
                    $ch = $channel->fresh();
                    $reason = $ch->last_error ?: 'Unknown — check push log';
                    $channel->update(['push_status' => 'error', 'last_error' => $reason]);
                    $this->log($channel, 'error', 'push_failed', "Push failed: {$reason}");
                }
            }
        }

        // ── Multi-destination watchdog ────────────────────────────────────
        $playlist = $this->playout->outputPlaylist($channel);
        if (file_exists($playlist)) {
            $this->push->watchDestinations($channel, $playlist);
        }

        if ($channel->stream_status !== 'live') {
            $channel->update(['stream_status' => 'live', 'source_live' => true]);
        }

        $dvrStatus = $this->ingest->isRunning($channel) ? 'recording' : 'idle';
        if ($channel->dvr_status !== $dvrStatus) {
            $channel->update(['dvr_status' => $dvrStatus]);
        }

        $channel->resetRetries();
    }

    protected function onSourceStillDown(Channel $channel): void
    {
        // ── Fallback playout watchdog ────────────────────────────────────
        if ($channel->playout_status === 'fallback' && !$this->playout->isFallbackRunning($channel)) {
            $this->log($channel, 'warning', 'fallback_restart', 'Fallback playout died — restarting');
            if ($this->playout->switchToFallback($channel)) {
                $channel->update(['playout_status' => 'fallback']);
                $this->log($channel, 'info', 'fallback_restarted', 'Fallback playout restarted');
            }
        }

        // ── Push watchdog — only if the process died (symlink handles content switch) ──
        if (!$this->push->isRunning($channel)) {
            $playlist = $this->playout->outputPlaylist($channel);
            if (file_exists($playlist)) {
                $this->log($channel, 'warning', 'push_died_offline', 'Push died during fallback — restarting');
                $this->push->start($channel);
            }
        }

        // ── Multi-destination watchdog ────────────────────────────────────
        $playlist = $this->playout->outputPlaylist($channel);
        if (file_exists($playlist)) {
            $this->push->watchDestinations($channel, $playlist);
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
