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

            // Kill any orphan listeners from a previous run before starting fresh.
            if ($channel->isPushIngest()) {
                $this->ingest->stopAllListeners($channel);
            }

            // 1. Start ingest — source → HLS segments → live.m3u8
            $this->ingest->start($channel);
            $channel->refresh();
            $this->log($channel, 'info', 'ingest_started', "Ingest PID {$channel->pid}");

            // 2. Mark playout as live — no process needed, push reads live.m3u8 directly
            $this->playout->switchToLive($channel);

            // 3. Start push immediately if playlist is ready — monitor will retry if not
            $this->startPushIfReady($channel);

            // 4. Start HLS relay to local nginx-rtmp for browser playback
            $this->startHlsRelay($channel);

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
        $this->stopHlsRelay($channel);
        $this->playout->stop($channel);
        if ($channel->isPushIngest()) {
            $this->ingest->stopAllListeners($channel);
        } else {
            $this->ingest->stop($channel);
        }

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

        // For push-ingest listeners and HTTP MPEG-TS IPTV streams, never call
        // ffprobe on the source URL — it opens a second connection which IPTV
        // providers detect as a duplicate session and kill the ingest stream.
        // Segment freshness is the sole health signal for these source types.
        $skipProbe = $channel->isPushIngest() || $this->ffmpeg->isIptvStream($channel);
        $probeHealthy = $skipProbe ? false : $this->ffmpeg->checkSourceHealth($channel);

        $sourceLive = ($ingestRunning && $recentSegments) || $probeHealthy;

        // For push-ingest (listener mode), the ingest ffmpeg is always running
        // and stale segments from a prior connection can persist ~20 s on disk.
        // To avoid a false "recovered" bounce, also verify that the live.m3u8
        // playlist is being actively updated by the current ffmpeg process.
        $liveM3u8 = $channel->dvr_directory . '/live.m3u8';
        $liveM3u8Fresh = file_exists($liveM3u8) && (time() - filemtime($liveM3u8)) <= max(5, (int) $channel->segment_duration * 2);

        $sourceRecovered = $channel->isPushIngest()
            ? ($ingestRunning && $recentSegments && $liveM3u8Fresh)
            : ($skipProbe ? ($ingestRunning && $recentSegments) : $probeHealthy);

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
            // Push ingest (listener): with -listen 1, the ffmpeg process stays
            // alive after the encoder disconnects (waiting for -timeout 120s).
            // During that window it holds the port but won't accept new
            // connections — causing vMix "Failed to connect to server".
            // Kill ALL orphan listeners on this port, then start a fresh one.
            // Skip segment cleaning to avoid breaking the push process that
            // reads from output.m3u8 → live.m3u8.
            try {
                $this->ingest->stopAllListeners($channel);
                $this->ingest->start($channel, cleanSegments: false);
                $this->log($channel, 'info', 'listener_restarted',
                    'RTMP listener force-restarted for reconnection');
            } catch (\Throwable $e) {
                $this->log($channel, 'error', 'listener_restart_failed',
                    'Failed to restart listener: ' . $e->getMessage());
            }
            $channel->update([
                'source_live'   => false,
                'record_pid'    => null,
                'stream_status' => 'offline',
                'dvr_status'    => 'idle',
                'record_status' => 'idle',
            ]);
        } else {
            // Pull ingest: stop the failed source.
            $this->ingest->stop($channel);
            $channel->update([
                'source_live'   => false,
                'pid'           => null,
                'record_pid'    => null,
                'stream_status' => 'offline',
                'dvr_status'    => 'idle',
                'record_status' => 'idle',
            ]);

            // ── Multi-source failover: try the next source before VOD ──
            if ($channel->hasMultipleSources()) {
                $next = $channel->nextSource();
                if ($next) {
                    $channel->activateSource($next);
                    try {
                        $this->ingest->start($channel);
                        $this->log($channel, 'info', 'source_switched',
                            "Switched to backup source [{$next->id}]: {$next->source_url}");
                        $this->alert->sendOfflineAlert($channel->fresh(), "Primary source down — using backup", $this->playout->hasFallback($channel));
                        return;
                    } catch (\Throwable $e) {
                        $this->log($channel, 'error', 'source_switch_failed',
                            "Backup source [{$next->id}] also failed: " . $e->getMessage());
                        $next->update(['last_error' => $e->getMessage()]);
                    }
                } else {
                    $this->log($channel, 'info', 'all_sources_exhausted',
                        'All backup sources exhausted — falling back to VOD');
                }
            }
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
        // For push-ingest (listener mode), the RTMP listener uses -listen 1
        // which exits after one connection. When the encoder disconnects,
        // onSourceLost already restarts the listener. Do NOT restart it here
        // during onSourceStillLive — it would kill the idle listener and
        // cause a brief freeze when the encoder reconnects.
        if (!$channel->isPushIngest() && !$this->ingest->isRunning($channel)) {
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
            // Backoff: after 5 consecutive failures, wait 60s before retry
            // to avoid burning CPU on a permanently broken destination.
            $consecutiveFails = $channel->retry_count ?? 0;
            if ($consecutiveFails >= 5) {
                $lastError = $channel->last_error ?? '';
                if (str_contains($lastError, 'authfailed') || str_contains($lastError, 'AccessManager')) {
                    // Auth error — don't retry until credentials are fixed
                    if ($channel->push_status !== 'error') {
                        $channel->update(['push_status' => 'error']);
                        $this->log($channel, 'error', 'push_auth_failed',
                            'Push destination rejected credentials — will not retry until fixed');
                    }
                    // Skip restart
                } else {
                    $playlist = $this->playout->outputPlaylist($channel);
                    if (file_exists($playlist) && $this->ffmpeg->hlsReady($channel, 2)) {
                        $this->log($channel, 'warning', 'push_died_offline', 'Push died during fallback — restarting');
                        $this->push->start($channel);
                    }
                }
            } else {
                $playlist = $this->playout->outputPlaylist($channel);
                if (file_exists($playlist) && $this->ffmpeg->hlsReady($channel, 2)) {
                    $wasPreviouslyLive = $channel->push_status === 'live';
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
                        $channel->incrementRetry('Push failed');
                    }
                }
            }
        }

        // ── Multi-destination watchdog ────────────────────────────────────
        $playlist = $this->playout->outputPlaylist($channel);
        if (file_exists($playlist)) {
            $this->push->watchDestinations($channel, $playlist);
        }

        // ── HLS relay watchdog ───────────────────────────────────────────
        // Only restart if the relay is truly dead AND we haven't tried
        // recently. Prevents hammering nginx-rtmp on repeated failures.
        if (! $this->isHlsRelayRunning($channel)) {
            $relayPidFile = $this->ffmpeg->pidFile($channel, 'hls_relay');
            $logFile = $this->ffmpeg->logFile($channel, 'hls_relay');
            $logAge = file_exists($logFile) ? time() - filemtime($logFile) : 999;
            if ($logAge > 15) {
                $this->startHlsRelay($channel);
            }
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
        // Only restart if the listener actually died — onSourceLost already
        // force-restarted it. If isRunning returns true, the listener is
        // waiting for encoder reconnection (which is what we want).
        if ($channel->isPushIngest() && ! $this->ingest->isRunning($channel)) {
            try {
                $this->ingest->stopAllListeners($channel);
                $this->ingest->start($channel, cleanSegments: false);
                $this->log($channel, 'info', 'listener_restarted_in_stilldown',
                    'Listener was dead — restarted');
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
            // Backoff: if fallback keeps failing, don't restart every tick.
            // After 3 failures, skip restart for 30 seconds.
            $retries = $channel->retry_count ?? 0;
            if ($retries >= 3) {
                // Don't restart — let it cool down
            } else {
                $this->log($channel, 'warning', 'fallback_restart', 'Fallback loop died — restarting');
                if ($this->playout->switchToFallback($channel)) {
                    $channel->update(['playout_status' => 'fallback', 'stream_status' => 'offline']);
                    $channel->resetRetries();
                    $this->log($channel, 'info', 'fallback_restarted', 'Fallback loop restarted');
                } else {
                    $channel->incrementRetry('Fallback restart failed');
                }
            }
        }

        // ── Push watchdog ────────────────────────────────────────────────
        if (!$this->push->isRunning($channel)) {
            $consecutiveFails = $channel->retry_count ?? 0;
            if ($consecutiveFails >= 5) {
                $lastError = $channel->last_error ?? '';
                if (str_contains($lastError, 'authfailed') || str_contains($lastError, 'AccessManager')) {
                    if ($channel->push_status !== 'error') {
                        $channel->update(['push_status' => 'error']);
                        $this->log($channel, 'error', 'push_auth_failed',
                            'Push destination rejected credentials — will not retry until fixed');
                    }
                } else {
                    $playlist = $this->playout->outputPlaylist($channel);
                    if (file_exists($playlist) && $this->ffmpeg->hlsReady($channel, 2)) {
                        $this->log($channel, 'warning', 'push_died_offline', 'Push died during fallback — restarting');
                        $this->push->start($channel);
                    }
                }
            } else {
                $playlist = $this->playout->outputPlaylist($channel);
                if (file_exists($playlist) && $this->ffmpeg->hlsReady($channel, 2)) {
                    $this->log($channel, 'warning', 'push_died_offline', 'Push died during fallback — restarting');
                    if ($this->push->start($channel)) {
                        $channel->resetRetries();
                    }
                }
            }
        }

        // ── Multi-destination watchdog ────────────────────────────────────
        $playlist = $this->playout->outputPlaylist($channel);
        if (file_exists($playlist)) {
            $this->push->watchDestinations($channel, $playlist);
        }

        // ── HLS relay watchdog ───────────────────────────────────────────
        if (! $this->isHlsRelayRunning($channel)) {
            $logFile = $this->ffmpeg->logFile($channel, 'hls_relay');
            $logAge = file_exists($logFile) ? time() - filemtime($logFile) : 999;
            if ($logAge > 15) {
                $this->startHlsRelay($channel);
            }
        }

        $channel->update(['stream_status' => 'offline']);
        $channel->incrementRetry('Source offline');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HLS RELAY (pushes output.m3u8 → local nginx-rtmp for HLS preview)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Start a relay that pushes channel output to the local nginx-rtmp
     * "static" application so it generates HLS files at:
     *   http://<host>:8081/hls-static/<slug>/index.m3u8
     */
    public function startHlsRelay(Channel $channel): bool
    {
        if ($this->isHlsRelayRunning($channel)) {
            return true;
        }

        $playlist = $this->playout->outputPlaylist($channel);
        if (! file_exists($playlist)) {
            return false;
        }

        $slug = $channel->slug;
        $rtmpUrl = "rtmp://rtmp:1935/static/{$slug}";

        $cmd = [
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            // No -re: HLS input already paces itself at live rate.
            // -re on an HLS file input causes freeze-burst stalls at segment
            // boundaries when the next segment isn't written yet.
            // No -timeout/-reconnect: output.m3u8 is a local file symlink —
            // those flags are HTTP-only and cause "Option not found" on file://.
            '-fflags',             '+genpts+discardcorrupt',
            '-live_start_index',   '-3',
            '-allowed_extensions', 'ALL',
            '-protocol_whitelist', 'file,crypto,data,http,https,tcp,tls',
            '-i',                  $playlist,
            '-max_muxing_queue_size', '9999',
            '-c:v', 'copy',
            '-c:a', 'copy',
            '-f',   'flv',
            '-rtmp_live', 'live',
            $rtmpUrl,
        ];

        $pidFile = $this->ffmpeg->pidFile($channel, 'hls_relay');
        $logFile = $this->ffmpeg->logFile($channel, 'hls_relay');

        try {
            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile, 6);
            $this->log($channel, 'info', 'hls_relay_started',
                "HLS relay started — PID {$pid} → {$rtmpUrl}");
            Log::info("[HLS Relay] {$channel->name} started — PID {$pid} → {$rtmpUrl}");
            return true;
        } catch (\Throwable $e) {
            Log::error("[HLS Relay] {$channel->name} failed: {$e->getMessage()}");
            $this->log($channel, 'error', 'hls_relay_failed', $e->getMessage());
            return false;
        }
    }

    public function stopHlsRelay(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'hls_relay');
        $pid = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
            Log::info("[HLS Relay] {$channel->name} stopped — PID {$pid}");
        }
        $this->ffmpeg->clearPid($pidFile);
    }

    public function isHlsRelayRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'hls_relay'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
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
