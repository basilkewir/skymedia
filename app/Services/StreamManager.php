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
        protected FFmpegService $ffmpeg,
        protected IngestService $ingest,
        protected PlayoutService $playout,
        protected PushService $push,
        protected DVRService $dvr,
        protected RecordingService $recording,
        protected AlertService $alert,
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    //  PUBLIC API
    // ═══════════════════════════════════════════════════════════════════

    public function startChannel(Channel $channel): bool
    {
        $channel->update(['is_active' => true, 'stream_status' => 'starting']);
        $this->log($channel, 'info', 'channel_starting', 'Starting channel');
        Log::info("[Debug] startChannel {$channel->name} begin");

        try {
            $dvrDir = $channel->dvr_directory;
            if (! is_dir($dvrDir)) {
                mkdir($dvrDir, 0755, true);
            }

            // 1. Ensure a slate exists so fallback ALWAYS has something to play.
            Log::info("[Debug] startChannel {$channel->name} step 1 ensureSlate");
            $this->playout->ensureSlate($channel);

            // 2. Start the fallback loop as warm standby (non-blocking).
            //    This ensures output.m3u8 → playout_X.m3u8 is always ready
            //    the instant the source goes offline.
            //    For push-ingest channels we skip this here: step 4 calls
            //    switchToFallback() which creates the active fallback slot and
            //    points output.m3u8 to it in one go. Calling ensureFallbackRunning
            //    first would start a second process in slot 'a' and cause
            //    duplicate fallback loops.
            if (! $channel->isPushIngest()) {
                try {
                    Log::info("[Debug] startChannel {$channel->name} step 2 ensureFallbackRunning");
                    $this->playout->ensureFallbackRunning($channel);
                    $this->log($channel, 'info', 'fallback_standyby',
                        'Fallback loop started as warm standby');
                } catch (\Throwable $e) {
                    $this->log($channel, 'warning', 'fallback_standyby_failed',
                        'Fallback warm standby failed (will retry on source loss): ' . $e->getMessage());
                }
            }

            // Kill any orphan listeners from a previous run before starting fresh.
            Log::info("[Debug] startChannel {$channel->name} step 2.5 stopAllListeners");
            if ($channel->isPushIngest()) {
                $this->ingest->stopAllListeners($channel);
            }

            // 3. Start ingest — source → HLS segments → live.m3u8
            Log::info("[Debug] startChannel {$channel->name} step 3 ingest start");
            $this->ingest->start($channel);
            $channel->refresh();
            $this->log($channel, 'info', 'ingest_started', "Ingest PID {$channel->pid}");

            // 4. For push-ingest channels, start in fallback mode so output.m3u8
            //    points to a real playlist immediately. Push can start right away
            //    and will serve VOD/slate until the encoder connects.
            //    For pull channels, mark as live (ingest is already running).
            Log::info("[Debug] startChannel {$channel->name} step 4 switchToLive/Fallback");
            if ($channel->isPushIngest()) {
                $this->playout->switchToFallback($channel);
            } else {
                $this->playout->switchToLive($channel);
            }

            // 5. Start push IMMEDIATELY — this MUST run 24/7, never stop.
            //    Push reads output.m3u8 which swaps between live/fallback
            //    via atomic symlink — no restart needed for transitions.
            Log::info("[Debug] startChannel {$channel->name} step 5 startPushAlways");
            $this->startPushAlways($channel);

            // 6. Start HLS relay to local nginx-rtmp for browser playback
            Log::info("[Debug] startChannel {$channel->name} step 6 startHlsRelay");
            $this->startHlsRelay($channel);

            $status = $channel->isPushIngest() ? 'starting' : 'live';
            $channel->update(['stream_status' => $status]);
            event(new StreamStatusChanged($channel, $status));

            return true;

        } catch (\Throwable $e) {
            $channel->update([
                'stream_status' => 'error',
                'last_error' => substr($e->getMessage(), 0, 1000),
            ]);
            $this->log($channel, 'error', 'stream_start_failed', $e->getMessage());
            Log::error("[Channel {$channel->id}] startChannel: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Start push and NEVER stop it. Only skip if auth credentials are rejected.
     * Push reads output.m3u8 — the symlink is swapped between live/fallback
     * without restarting the ffmpeg process.
     */
    private function startPushAlways(Channel $channel): void
    {
        Log::info("[Debug] startPushAlways {$channel->name} begin");
        if (empty($channel->push_url)) {
            Log::info("[Debug] startPushAlways {$channel->name} no push_url");
            return;
        }

        $lastError = $channel->last_error ?? '';
        if (str_contains($lastError, 'authfailed') || str_contains($lastError, 'AccessManager')) {
            if ($channel->push_status !== 'error') {
                $channel->update(['push_status' => 'error']);
                $this->log($channel, 'error', 'push_auth_failed',
                    'Push destination rejected credentials — will not retry until fixed');
            }

            return;
        }

        $playlist = $this->playout->outputPlaylist($channel);
        Log::info("[Debug] startPushAlways {$channel->name} playlist={$playlist} exists=" . (file_exists($playlist) ? 'yes' : 'no') . ' is_link=' . (is_link($playlist) ? 'yes' : 'no'));
        // For push-ingest channels output.m3u8 is a symlink that may point to
        // live.m3u8 (which doesn't exist yet — encoder hasn't connected) or to
        // a fallback playlist. file_exists() returns false for dangling symlinks,
        // so we must check is_link() to allow push to start in fallback mode.
        if (! file_exists($playlist) && ! is_link($playlist)) {
            Log::info("[Debug] startPushAlways {$channel->name} playlist missing (no symlink)");
            return;
        }

        Log::info("[Debug] startPushAlways {$channel->name} calling push->start");
        if ($this->push->start($channel)) {
            $channel->update(['push_status' => 'live']);
            $this->log($channel, 'info', 'push_started', 'Push started — 24/7 mode');
        } else {
            $reason = $channel->fresh()->last_error ?: 'Unknown — check push log';
            $channel->update(['push_status' => 'error', 'last_error' => $reason]);
            $this->log($channel, 'error', 'push_failed', "Push failed: {$reason}");
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
            'pid' => null,
            'playout_pid' => null,
            'push_pid' => null,
            'record_pid' => null,
            'stream_status' => 'stopped',
            'playout_status' => 'stopped',
            'push_status' => 'stopped',
            'dvr_status' => 'idle',
            'record_status' => 'idle',
            'source_live' => false,
            'is_active' => false,
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

    /**
     * Refresh ingest without stopping the external push.
     * For pull channels: stops only the ingest, tries next source in failover
     * chain, restarts ingest. Push continues running (pushing DVR/fallback).
     * For push-ingest channels: falls back to full restartChannel().
     */
    public function refreshIngest(Channel $channel): bool
    {
        if ($channel->isPushIngest()) {
            return $this->restartChannel($channel);
        }

        try {
            $this->log($channel, 'info', 'refreshing_ingest',
                'Refreshing ingest — push continues running');

            // 1. Stop only the ingest (not push, not playout, not recording)
            $this->ingest->stop($channel);
            $channel->update(['pid' => null, 'source_live' => false]);

            // 2. Try next sources in the failover chain
            //    cleanSegments=false preserves live.m3u8 so push keeps running
            if ($channel->hasMultipleSources()) {
                while ($next = $channel->nextSource()) {
                    try {
                        // Temporarily set source_url for this attempt (don't activate yet)
                        $prevUrl = $channel->source_url;
                        $prevSourceId = $channel->current_source_id;
                        $channel->update([
                            'source_url' => $next->source_url,
                            'source_type' => $next->source_type,
                        ]);

                        $this->ingest->start($channel, cleanSegments: false);
                        $this->log($channel, 'info', 'source_switched',
                            "Refresh: trying source [{$next->id}]: {$next->source_url}");

                        // 3. Wait for live.m3u8 to have segments
                        $start = time();
                        $maxWait = max(10, (int) $channel->segment_duration * 3 + 2);
                        while (time() - $start < $maxWait) {
                            if ($this->ffmpeg->liveHlsReady($channel->fresh(), 2)) {
                                break;
                            }
                            usleep(250_000);
                        }

                        if ($this->ffmpeg->liveHlsReady($channel->fresh(), 2)) {
                            // Source is working — NOW activate it permanently
                            $channel->activateSource($next);
                            $this->playout->switchToLive($channel->fresh());
                            $channel->update([
                                'source_live' => true,
                                'last_live_at' => now(),
                                'stream_status' => 'live',
                                'playout_status' => 'live',
                            ]);
                            $channel->resetRetries();
                            $this->alert->sendRecoveryAlert($channel->fresh());
                            event(new StreamStatusChanged($channel, 'live'));
                            $this->log($channel, 'info', 'refresh_recovered',
                                'Ingest refreshed — source recovered');

                            return true;
                        }
                        // live.m3u8 not ready — this source is also dead, revert
                        $this->log($channel, 'warning', 'source_refresh_no_segments',
                            "Source [{$next->id}] started but no segments — trying next");
                        $this->ingest->stop($channel);
                        $channel->update([
                            'source_url' => $prevUrl,
                            'current_source_id' => $prevSourceId,
                            'pid' => null,
                        ]);
                    } catch (\Throwable $e) {
                        $this->log($channel, 'error', 'source_refresh_failed',
                            "Source [{$next->id}] failed: " . $e->getMessage());
                        $next->update(['last_error' => $e->getMessage()]);
                        $channel->refresh();
                    }
                }
                $this->log($channel, 'info', 'all_sources_exhausted',
                    'Refresh: all backup sources exhausted — staying in fallback');
                // Reset current_source_id so next refresh cycles from priority 1
                $channel->update(['current_source_id' => null, 'source_url' => $channel->channelSources()->where('is_active', true)->orderBy('priority')->first()?->source_url
                    ?? $channel->source_url]);
            } else {
                // No multiple sources — try restarting the same source
                try {
                    $this->ingest->start($channel, cleanSegments: false);
                    $start = time();
                    $maxWait = max(10, (int) $channel->segment_duration * 3 + 2);
                    while (time() - $start < $maxWait) {
                        if ($this->ffmpeg->liveHlsReady($channel->fresh(), 2)) {
                            break;
                        }
                        usleep(250_000);
                    }

                    if ($this->ffmpeg->liveHlsReady($channel->fresh(), 2)) {
                        $this->playout->switchToLive($channel->fresh());
                        $channel->update([
                            'source_live' => true,
                            'last_live_at' => now(),
                            'stream_status' => 'live',
                            'playout_status' => 'live',
                        ]);
                        $channel->resetRetries();
                        $this->alert->sendRecoveryAlert($channel->fresh());
                        event(new StreamStatusChanged($channel, 'live'));
                        $this->log($channel, 'info', 'refresh_recovered',
                            'Ingest refreshed — source recovered');

                        return true;
                    }
                    $this->log($channel, 'warning', 'source_refresh_no_segments',
                        'Refresh: source started but no segments — staying in fallback');
                    $this->ingest->stop($channel);
                    $channel->update(['pid' => null]);
                } catch (\Throwable $e) {
                    $this->log($channel, 'error', 'source_refresh_failed',
                        'Refresh failed: ' . $e->getMessage());
                }
            }

            return false;
        } catch (\Throwable $e) {
            $this->log($channel, 'error', 'refresh_error', $e->getMessage());
            Log::error("[Channel {$channel->id}] refreshIngest: {$e->getMessage()}");

            return false;
        }
    }

    public function activateAll(): void
    {
        Channel::where('is_active', true)
            ->whereIn('stream_status', ['idle', 'stopped', 'error', 'offline'])
            ->each(fn (Channel $c) => $this->startChannel($c));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  MONITOR TICK
    // ═══════════════════════════════════════════════════════════════════

    public function monitorChannel(Channel $channel): void
    {
        if (! $channel->is_active) {
            return;
        }

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

        if ($sourceRecovered && ! $channel->source_live) {
            $this->onSourceRecovered($channel->fresh());
        } elseif (! $sourceLive && $channel->source_live) {
            $this->onSourceLost($channel->fresh());
        } elseif ($sourceLive) {
            $this->onSourceStillLive($channel->fresh());
        } else {
            $this->onSourceStillDown($channel->fresh());
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  STATE TRANSITIONS
    //
    //  24/7 PUSH RULES:
    //  - Push NEVER stops once started (except operator manual stop)
    //  - Push reads output.m3u8 — atomic symlink swap handles live↔fallback
    //  - Push always uses -c:v copy (no branding) — no restart for transitions
    //  - Branding (logo/ticker) is baked into fallback content by PlayoutService
    //  - Fallback loop runs as warm standby — always ready for instant swap
    // ═══════════════════════════════════════════════════════════════════

    protected function onSourceLost(Channel $channel): void
    {
        $this->log($channel, 'warning', 'source_lost',
            'Source offline — switching playout to fallback');

        // Recording reads from live.m3u8. When source drops, the recording
        // ffmpeg will fail naturally (no more segments). Do NOT proactively
        // stop it — let it die on its own to record as much as possible.
        // Stale recording detection in onSourceStillDown handles cleanup.

        if ($channel->isPushIngest()) {
            // Push ingest (loop wrapper): the shell loop keeps ffmpeg restarting
            // automatically after the encoder disconnects — do NOT kill and
            // restart the loop here. Just update state and let the loop handle it.
            // Killing the loop is what caused "Failed to connect to server".
            $channel->update([
                'source_live' => false,
                'stream_status' => 'offline',
                'dvr_status' => 'idle',
            ]);
            $this->log($channel, 'info', 'listener_waiting',
                'Encoder disconnected — listener loop waiting for reconnect');
        } else {
            // Pull ingest: stop the failed source.
            $this->ingest->stop($channel);
            $channel->update([
                'source_live' => false,
                'pid' => null,
                'stream_status' => 'offline',
                'dvr_status' => 'idle',
            ]);

            // ── Multi-source failover: try ALL remaining sources before VOD ──
            if ($channel->hasMultipleSources()) {
                while ($next = $channel->nextSource()) {
                    $channel->activateSource($next);
                    try {
                        $this->ingest->start($channel);
                        $this->log($channel, 'info', 'source_switched',
                            "Switched to backup source [{$next->id}]: {$next->source_url}");
                        $this->alert->sendOfflineAlert($channel->fresh(), 'Primary source down — using backup', $this->playout->hasFallback($channel));

                        return;
                    } catch (\Throwable $e) {
                        $this->log($channel, 'error', 'source_switch_failed',
                            "Backup source [{$next->id}] failed: " . $e->getMessage());
                        $next->update(['last_error' => $e->getMessage()]);
                        $channel->refresh();
                    }
                }
                $this->log($channel, 'info', 'all_sources_exhausted',
                    'All backup sources exhausted — falling back to VOD');
            }
        }

        $this->alert->sendOfflineAlert($channel->fresh(), 'Source unreachable', $this->playout->hasFallback($channel));

        // ── Switch to fallback immediately for both push and pull ingest ──
        // switchToFallback generates slate on-demand if no recordings/VOD exist yet.
        if ($this->playout->switchToFallback($channel->fresh())) {
            $channel->update(['playout_status' => 'fallback']);
            $this->log($channel, 'info', 'fallback_activated', 'Playout switched to VOD loop');
            event(new StreamStatusChanged($channel, 'offline'));

            // Branding is now baked into fallback content at the playout level.
            // Push always uses -c:v copy — no restart needed for transitions.
        } else {
            $this->log($channel, 'error', 'fallback_failed', 'Fallback playout failed to start');
            event(new StreamStatusChanged($channel, 'offline'));
        }
    }

    protected function onSourceRecovered(Channel $channel): void
    {
        if (! $channel->isPushIngest()) {
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
            if ($this->ffmpeg->liveHlsReady($channel->fresh(), 2)) {
                break;
            }
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
        $this->log($channel, 'info', 'switched_to_live', 'Playout switched to live stream');

        // Branding is now baked into fallback content at the playout level.
        // Push always uses -c:v copy — no restart needed for transitions.

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
        if (! $channel->isPushIngest() && ! $this->ingest->isRunning($channel)) {
            $this->log($channel, 'warning', 'ingest_died', 'Ingest died — restarting');
            try {
                $this->ingest->start($channel);
            } catch (\Throwable $e) {
                $this->log($channel, 'error', 'ingest_restart_failed', $e->getMessage());

                return;
            }
        }

        // ── Playout: always make sure output.m3u8 points to live.m3u8 ─
        if (! $this->playout->isLiveOutput($channel) && $this->ffmpeg->liveHlsReady($channel, 2)) {
            $fresh = $channel->fresh();
            $this->playout->switchToLive($fresh);
            // Branding is now baked into fallback content — no push restart needed
            $this->log($channel, 'info', 'playout_forced_live',
                'output.m3u8 was not pointing to live — corrected');
        }

        // ── Push watchdog: push MUST always be running 24/7 ─────────────
        if (! empty($channel->push_url)) {
            $wasRunning = $channel->push_status === 'live';
            if ($this->push->ensureRunning($channel->fresh())) {
                if (! $wasRunning) {
                    $this->log($channel, 'info', 'push_restarted', 'Push restarted by watchdog (24/7)');
                    $channel->resetRetries();
                }
            }
        }

        // ── Multi-destination watchdog ────────────────────────────────────
        $playlist = $this->playout->outputPlaylist($channel);
        if (file_exists($playlist)) {
            $this->push->watchDestinations($channel, $playlist);
        }

        // ── HLS relay watchdog: always running for browser preview ────────
        if (! $this->isHlsRelayRunning($channel)) {
            $this->startHlsRelay($channel);
        }

        // ── Fallback loop warm standby: keep alive for instant failover ──
        if (! $this->playout->isFallbackRunning($channel)) {
            try {
                $this->playout->ensureFallbackRunning($channel);
                $this->log($channel, 'info', 'fallback_standyby_restored',
                    'Fallback warm standby restored');
            } catch (\Throwable $e) {
                // Non-critical — will retry next tick
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
        // Detect stale recording: ffmpeg process running but output file not
        // growing (source dropped, live.m3u8 stopped getting segments).
        if ($this->recording->isStale($channel)) {
            $this->log($channel, 'warning', 'recording_stale',
                'Recording file stopped growing — stopping stale recording');
            $this->recording->stop($channel);
            $channel->refresh();
        }

        // Keep RTMP/SRT listeners available while the publisher is offline.
        // The loop wrapper restarts ffmpeg automatically — only restart the
        // loop itself if the loop process has completely died (not just because
        // the encoder disconnected, which is normal and handled by the loop).
        if ($channel->isPushIngest()) {
            $loopPid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'ingest'));
            $loopDead = ! ($loopPid > 0 && $this->ffmpeg->isRunning($loopPid));
            if ($loopDead && ! $this->ingest->isRunning($channel)) {
                try {
                    $this->ingest->stopAllListeners($channel);
                    $this->ingest->start($channel, cleanSegments: false);
                    $this->log($channel, 'info', 'listener_loop_restarted',
                        'Listener loop was dead — restarted');
                } catch (\Throwable $e) {
                    $this->log($channel, 'error', 'ingest_listener_restart_failed', $e->getMessage());
                }
            }
        }

        // ── If no fallback was available when source dropped, keep trying ──
        if ($channel->playout_status !== 'fallback') {
            if ($this->playout->switchToFallback($channel)) {
                $channel->update(['playout_status' => 'fallback', 'stream_status' => 'offline']);
                $this->log($channel, 'info', 'fallback_activated', 'Playout switched to VOD loop');
                event(new StreamStatusChanged($channel, 'offline'));
                // Branding is now baked into fallback content — no push restart needed
            }
        }

        // ── Fallback watchdog: restart loop if it died (NO retry limit) ──
        if ($channel->playout_status === 'fallback' && ! $this->playout->isFallbackRunning($channel)) {
            $this->log($channel, 'warning', 'fallback_restart', 'Fallback loop died — restarting');
            if ($this->playout->switchToFallback($channel)) {
                $channel->update(['playout_status' => 'fallback', 'stream_status' => 'offline']);
                $this->log($channel, 'info', 'fallback_restarted', 'Fallback loop restarted');
                // Branding is now baked into fallback content — no push restart needed
            } else {
                $this->log($channel, 'error', 'fallback_restart_failed', 'Fallback restart failed');
            }
        }

        // ── Push watchdog: push MUST always be running 24/7 ─────────────
        if (! empty($channel->push_url)) {
            $wasRunning = $channel->push_status === 'live';
            if ($this->push->ensureRunning($channel->fresh())) {
                if (! $wasRunning) {
                    $this->log($channel, 'info', 'push_restarted', 'Push restarted by watchdog (24/7)');
                    $channel->resetRetries();
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
            $this->startHlsRelay($channel);
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

        // The tracked HLS relay is dead — kill any orphan relays for this
        // channel before starting a fresh one.
        $this->killOrphanHlsRelays($channel);

        $playlist = $this->playout->outputPlaylist($channel);
        if (! file_exists($playlist)) {
            return false;
        }

        $slug = $channel->slug;
        $rtmpUrl = "rtmp://rtmp:1935/static/{$slug}";

        $cmd = [
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            '-fflags',             '+genpts+discardcorrupt',
            '-live_start_index',   '-3',
            '-allowed_extensions', 'ALL',
            '-protocol_whitelist', 'file,crypto,data,http,https,tcp,tls',
            '-max_reload',         '1000',
            '-m3u8_hold_counters', '1000',
            '-i',                  $playlist,
            '-max_muxing_queue_size', '4096',
            '-c:v', 'copy',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-ar',  '48000',
            '-ac',  '2',
            '-f',   'flv',
            '-rtmp_live', 'live',
            // LLOD v3 — low-latency flags for instant playback on nginx-rtmp
            '-flvflags',           'no_duration_filesize',
            '-flags',              '+global_header',
            '-bsf:v',              'h264_mp4toannexb',
            '-force_key_frames',   'expr:gte(t,n_forced*2)',
            '-bf',                 '0',
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
        $this->killOrphanHlsRelays($channel);
        $this->ffmpeg->clearPid($pidFile);
    }

    /**
     * Kill any ffmpeg HLS relay processes for this channel that are not
     * tracked by the PID file. Prevents duplicate relays after restarts.
     */
    private function killOrphanHlsRelays(Channel $channel): int
    {
        $rtmpUrl = "rtmp://rtmp:1935/static/{$channel->slug}";
        exec("ps aux | grep -F " . escapeshellarg($rtmpUrl) . " | grep -F 'ffmpeg' | grep -v grep | awk '{print \$2}' 2>/dev/null", $lines);

        $count = 0;
        foreach ($lines as $line) {
            $pid = (int) trim($line);
            if ($pid > 0) {
                exec("kill -KILL {$pid} 2>/dev/null");
                $count++;
            }
        }

        if ($count > 0) {
            Log::warning("[HLS Relay] {$channel->name} killed {$count} orphan relay(s)");
        }

        return $count;
    }

    public function isHlsRelayRunning(Channel $channel): bool
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'hls_relay');
        $pid = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0 && $this->ffmpeg->isRunning($pid)) {
            return true;
        }

        // PID file stale — reconcile with the actual relay process.
        $pids = $this->findHlsRelayPids($channel);
        if (! empty($pids)) {
            file_put_contents($pidFile, (string) $pids[0]);

            return true;
        }

        return false;
    }

    private function findHlsRelayPids(Channel $channel): array
    {
        $rtmpUrl = "rtmp://rtmp:1935/static/{$channel->slug}";
        exec("ps aux | grep -F " . escapeshellarg($rtmpUrl) . " | grep -F 'ffmpeg' | grep -v grep | awk '{print \$2}' 2>/dev/null", $lines);

        return array_values(array_filter(array_map('intval', $lines)));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * True when the listener loop process (shell wrapper) for a push-ingest
     * channel is still running. The loop keeps ffmpeg restarting automatically
     * after encoder disconnects — we only need to intervene if the loop dies.
     */
    public function isListenerLoopRunning(Channel $channel): bool
    {
        if (! $channel->isPushIngest()) {
            return false;
        }
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'ingest'));

        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  LOGGING
    // ═══════════════════════════════════════════════════════════════════

    protected function log(Channel $channel, string $level, string $event, string $message, ?array $meta = null): void
    {
        try {
            StreamLog::create([
                'channel_id' => $channel->id,
                'level' => $level,
                'event' => $event,
                'message' => $message,
                'metadata' => $meta,
            ]);
        } catch (\Throwable $e) {
            Log::error("StreamLog write: {$e->getMessage()}");
        }
    }
}
