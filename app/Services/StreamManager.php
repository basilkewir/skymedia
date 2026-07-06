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

            $status = $channel->isPushIngest() ? 'starting' : 'live';
            $channel->update(['stream_status' => $status]);
            event(new StreamStatusChanged($channel, $status));
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

        // Primary health signal: is the ingest process running and writing fresh segments?
        $ingestRunning = $this->ingest->isRunning($channel);
        $recentSegments = $this->ffmpeg->hasRecentSegments($channel, 20);
        // A listener cannot be probed without consuming its single incoming
        // connection. Fresh output segments are its health signal instead.
        $probeHealthy = $channel->isPushIngest() ? false : $this->ffmpeg->checkSourceHealth($channel);

        $sourceLive = ($ingestRunning && $recentSegments) || $probeHealthy;

        // For push-ingest (listener mode), the ingest ffmpeg is always running
        // and stale segments from a prior connection can persist ~20 s on disk.
        // To avoid a false "recovered" bounce, also verify that the live.m3u8
        // playlist is being actively updated by the current ffmpeg process.
        $liveM3u8 = $channel->dvr_directory . '/live.m3u8';
        $liveM3u8Fresh = file_exists($liveM3u8) && (time() - filemtime($liveM3u8)) <= max(5, (int) $channel->segment_duration * 2);

        $sourceRecovered = $channel->isPushIngest()
            ? ($ingestRunning && $recentSegments && $liveM3u8Fresh)
            : $probeHealthy;

        if ($sourceRecovered && !$channel->source_live) {
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
            'Source offline — switching playout to fallback');

        $this->recording->stop($channel);

        if ($channel->isPushIngest()) {
            // Push ingest (listener): keep the ingest running so the
            // encoder can reconnect without a restart cycle.
            $channel->update([
                'source_live'   => false,
                'record_pid'    => null,
                'stream_status' => 'offline',
                'dvr_status'    => 'idle',
                'record_status' => 'idle',
            ]);
        } else {
            $this->ingest->stop($channel);
            $channel->update([
                'source_live'   => false,
                'pid'           => null,
                'record_pid'    => null,
                'stream_status' => 'offline',
                'dvr_status'    => 'idle',
                'record_status' => 'idle',
            ]);
        }

        $this->alert->sendOfflineAlert($channel->fresh(), 'Source unreachable', $this->playout->hasFallback($channel));

        if ($this->playout->hasFallback($channel->fresh())) {
            if ($this->playout->switchToFallback($channel->fresh())) {
                $channel->update(['playout_status' => 'fallback']);
                $this->log($channel, 'info', 'fallback_activated', 'Playout switched to VOD loop');
                event(new StreamStatusChanged($channel, 'offline'));
                // Only restart push if branding overlay (logo/ticker) needs to change.
                // Without branding the ffmpeg command is identical for live and fallback
                // (-c:v copy -c:a copy), so the atomic symlink swap is sufficient.
                if ($this->hasBranding($channel->fresh())) {
                    $this->push->stop($channel->fresh());
                    $this->startPushIfReady($channel->fresh());
                }
            } else {
                $this->log($channel, 'error', 'fallback_failed', 'Fallback playout failed to start');
                event(new StreamStatusChanged($channel, 'offline'));
            }
        } else {
            $this->log($channel, 'warning', 'no_fallback', 'No recording yet — will retry fallback each tick');
            event(new StreamStatusChanged($channel, 'offline'));
        }
    }

    protected function onSourceRecovered(Channel $channel): void
    {
        if (!$channel->isPushIngest()) {
            // Pull ingest: restart to pick up the new stream
            $this->ingest->stop($channel);
            try {
                $this->ingest->start($channel);
            } catch (\Throwable $e) {
                $this->log($channel, 'error', 'ingest_restart_failed', $e->getMessage());
                return;
            }
        } else {
            // Push ingest (listener): the encoder reconnected to the
            // still-listening ffmpeg. No stop/start needed — the ingest
            // is already receiving the stream and writing segments.
            $this->log($channel, 'info', 'source_recovered', 'Source back online — listener active');
        }

        // Wait for live.m3u8 to have segments, then swap the symlink.
        // Push keeps running — ffmpeg picks up live.m3u8 on next refresh.
        $start = time();
        $maxWait = max(10, (int) $channel->segment_duration * 3 + 2);
        while (time() - $start < $maxWait) {
            if ($this->ffmpeg->liveHlsReady($channel->fresh(), 2)) break;
            usleep(250_000);
        }

        if (! $this->ffmpeg->liveHlsReady($channel->fresh(), 2)) {
            $this->log($channel, 'warning', 'live_playout_not_ready',
                'Live source returned but its playback buffer is not ready; fallback remains on air');
            return;
        }

        // Atomically symlink output.m3u8 → live.m3u8.
        // Fallback loop stays running in the background for instant switch-back.
        $this->playout->switchToLive($channel->fresh());

        // Only restart push if branding overlay (logo/ticker) needs to change.
        // Without branding the ffmpeg command is identical for live and fallback,
        // so the atomic symlink swap is sufficient and avoids a viewer gap.
        if ($this->hasBranding($channel->fresh())) {
            $this->push->stop($channel->fresh());
            $this->startPushIfReady($channel->fresh());
        }

        $channel->update([
            'source_live' => true, 'last_live_at' => now(),
            'stream_status' => 'live', 'playout_status' => 'live',
        ]);
        $channel->resetRetries();
        $this->alert->sendRecoveryAlert($channel->fresh());
        event(new StreamStatusChanged($channel, 'live'));
    }

    protected function onSourceStillLive(Channel $channel): void
    {
        // Managed push-ingest channels are relay-only and must never create
        // timed recordings, including when an older database value enabled it.
        if ($channel->isPushIngest() && $this->recording->isRunning($channel)) {
            $this->recording->stop($channel);
            $channel->refresh();
        }

        // ── Recording lifecycle ──────────────────────────────────────────
        if ($this->recording->justFinished($channel)) {
            $this->recording->finish($channel);
            $channel->refresh();
            $this->log($channel, 'info', 'recording_completed',
                "Completed: {$channel->fallback_recording_path}");
        }

        $this->recording->refreshProgress($channel);
        $this->recording->abortIfDiskFull($channel);

        if ($this->recording->shouldRecord($channel)) {
            if ($this->recording->start($channel)) {
                $this->log($channel, 'info', 'recording_started',
                    "Recording started ({$channel->record_duration}s)");
            }
        }

        // ── DVR rolling window ───────────────────────────────────────────
        if (! $channel->isPushIngest() && $channel->dvr_enabled !== false) {
            $this->dvr->syncSegments($channel);
        }

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

        // ── Playout: always make sure output.m3u8 points to live.m3u8 when source is live ─
        if (!$this->playout->isLiveOutput($channel) && $this->ffmpeg->liveHlsReady($channel, 2)) {
            $fresh = $channel->fresh();
            $this->playout->switchToLive($fresh);
            // Only restart push if branding overlay needs to change
            if ($this->hasBranding($fresh)) {
                $this->push->stop($fresh);
                $this->startPushIfReady($fresh);
            }
            $this->log($channel, 'info', 'playout_forced_live',
                'output.m3u8 was not pointing to live — corrected');
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
                if ($this->push->start($channel->fresh())) {
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

        $dvrStatus = ! $channel->isPushIngest()
            && $channel->dvr_enabled !== false && $this->ingest->isRunning($channel)
            ? 'recording'
            : 'idle';
        if ($channel->dvr_status !== $dvrStatus) {
            $channel->update(['dvr_status' => $dvrStatus]);
        }

        $channel->resetRetries();
    }

    protected function onSourceStillDown(Channel $channel): void
    {
        // Stop any stale recording from a previous onSourceStillLive cycle.
        if ($this->recording->isRunning($channel)) {
            $this->recording->stop($channel);
        }

        // Keep RTMP/SRT listeners available while the publisher is offline.
        if ($channel->isPushIngest() && ! $this->ingest->isRunning($channel)) {
            try {
                $this->ingest->start($channel);
            } catch (\Throwable $e) {
                $this->log($channel, 'error', 'ingest_listener_restart_failed', $e->getMessage());
            }
        }

        // ── If no fallback was available when source dropped, keep trying ──
        if ($channel->playout_status !== 'fallback' && $this->playout->hasFallback($channel)) {
            $this->log($channel, 'info', 'fallback_now_available', 'Recording ready — switching to VOD loop');
            if ($this->playout->switchToFallback($channel)) {
                $channel->update(['playout_status' => 'fallback', 'stream_status' => 'offline']);
                $this->log($channel, 'info', 'fallback_activated', 'Playout switched to VOD loop');
                event(new StreamStatusChanged($channel, 'offline'));
            }
        }

        // ── Fallback watchdog — restart loop if it died ──────────────────
        if ($channel->playout_status === 'fallback' && !$this->playout->isFallbackRunning($channel)) {
            $this->log($channel, 'warning', 'fallback_restart', 'Fallback loop died — restarting');
            if ($this->playout->switchToFallback($channel)) {
                $channel->update(['playout_status' => 'fallback', 'stream_status' => 'offline']);
                $this->log($channel, 'info', 'fallback_restarted', 'Fallback loop restarted');
            }
        }

        // ── Push watchdog ────────────────────────────────────────────────
        if (!$this->push->isRunning($channel)) {
            $playlist = $this->playout->outputPlaylist($channel);
            if (file_exists($playlist) && $this->ffmpeg->hlsReady($channel, 2)) {
                $this->log($channel, 'warning', 'push_died_offline', 'Push died during fallback — restarting');
                $this->push->start($channel);
            }
        }

        // ── Multi-destination watchdog ────────────────────────────────────
        $playlist = $this->playout->outputPlaylist($channel);
        if (file_exists($playlist)) {
            $this->push->watchDestinations($channel, $playlist);
        }

        $channel->update(['stream_status' => 'offline']);
        $channel->incrementRetry('Source offline');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * True when the channel has a logo or ticker overlay configured.
     * When branding is present, live and fallback modes use different ffmpeg
     * encoding flags, so the push process must be restarted on transitions.
     * Without branding both modes use -c:v copy, so the symlink swap is
     * sufficient and no restart is needed.
     */
    private function hasBranding(Channel $channel): bool
    {
        $channel->loadMissing('logoMedia');
        $logo = $channel->logoMedia;

        return ($logo && file_exists($logo->filepath))
            || ($channel->ticker_enabled && trim((string) $channel->ticker_text) !== '');
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
