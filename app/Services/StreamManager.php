<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\StreamStatusChanged;
use App\Models\Channel;
use App\Models\ChannelSource;
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
            if ($channel->isPushIngest() && $channel->source_type === 'srt') {
                $this->ingest->stopAllListeners($channel);
            }

            // 3. Start ingest — source → HLS segments → live.m3u8
            //    For RTMP push channels, ingest is triggered by the on_publish
            //    callback when the encoder connects to port 1935 — skip here.
            Log::info("[Debug] startChannel {$channel->name} step 3 ingest start");
            if ($channel->isPushIngest() && $channel->source_type === 'rtmp') {
                // RTMP push: try starting HLS pull immediately in case
                // nginx-rtop already has the encoder connected (e.g. app
                // restarted while encoder was pushing). If nginx-rtop doesn't
                // have the stream yet, ffmpeg will retry via reconnect flags.
                // The on_publish callback will also trigger startHlsPull on
                // fresh encoder connections.
                try {
                    $this->ingest->startHlsPull($channel);
                    $this->log($channel, 'info', 'hls_pull_started',
                        'HLS pull started (encoder may already be connected)');
                } catch (\Throwable $e) {
                    // Not fatal — on_publish will retry when encoder reconnects
                    $this->log($channel, 'info', 'ingest_waiting',
                        'Waiting for encoder to connect to port 1935: ' . $e->getMessage());
                    $channel->update([
                        'stream_status' => 'starting',
                        'source_live' => false,
                    ]);
                }
            } else {
                $this->ingest->start($channel);
                $channel->refresh();
                $this->log($channel, 'info', 'ingest_started', "Ingest PID {$channel->pid}");
            }

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

            // Both pull and push ingest start in "starting" and are promoted to
            // "live" by the monitor once segment evidence confirms the source
            // is actually producing. Previously pull ingest was marked "live"
            // immediately which created a stale state if the source never came
            // back — masking the failure in the dashboard.
            $channel->update(['stream_status' => 'starting']);
            event(new StreamStatusChanged($channel, 'starting'));

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
    private function startPushAlways(Channel $channel, bool $force = false): void
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
        if ($this->push->start($channel, force: $force)) {
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
        if ($channel->isPushIngest() && $channel->source_type === 'srt') {
            // SRT push: stop the listener loop and all orphan processes
            $this->ingest->stopAllListeners($channel);
        } else {
            // RTMP push or pull ingest: just stop the ffmpeg process
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
     *
     * Pull channels: stops only the ingest, tries the next source in the
     * failover chain, restarts ingest. Push continues running (pushing
     * DVR/fallback). Push-ingest channels: falls back to full restartChannel().
     *
     * Implementation notes (the bugs this fixes):
     *   • The previous implementation synchronously waited up to 10s per
     *     source for `liveHlsReady`. With several channels in fallback and
     *     multiple backup sources, this stalled the single-threaded monitor
     *     for minutes at a time — `last_check_at` for some channels drifted
     *     >40 min stale and onSourceLost/onSourceRecovered never ran for
     *     them, even though `ingest->start` had set `source_live=true`
     *     on a process that exited seconds later. The dashboard showed
     *     `live=1 pl=fallback` for hours — exactly the user-visible symptom.
     *
     *   • The wait is now capped at a few seconds per source. If segments
     *     do not arrive in that window, we leave the channel in
     *     `stream_status='starting'` and let the *next* monitor tick either
     *     promote it via onSourceRecovered (segments arrived) or demote it
     *     via onSourceStillDown (they did not), re-entering the cooldown-
     *     gated refresh loop. State is ALWAYS cleaned up before returning,
     *     so no stale `source_live=true` can survive a failed refresh.
     */
    public function refreshIngest(Channel $channel): bool
    {
        if ($channel->isPushIngest()) {
            return $this->restartChannel($channel);
        }

        // Capped, per-source confirmation wait. Long enough to catch fast
        // HLS/MPEG-TS providers (first segment in ≤1 seg duration), short
        // enough that the monitor never blocks long enough to starve other
        // channels. The next monitor tick confirms slower sources.
        $maxWait = max(2, min(5, (int) $channel->segment_duration * 2));

        try {
            $this->log($channel, 'info', 'refreshing_ingest',
                'Refreshing ingest — push continues running');

            // 1. Stop only the ingest (not push, not playout, not recording).
            $this->ingest->stop($channel);
            $channel->update([
                'pid' => null,
                'source_live' => false,
                'stream_status' => 'offline',
            ]);

            // 2. Try the next source in the failover chain (or restart the
            //    same one if there is only one).
            if ($channel->hasMultipleSources()) {
                // Failover (wrap-around). Point the channel at the NEXT source
                // and start ingest, but do NOT declare it live here. The monitor
                // confirms via real segment evidence on the next tick
                // (onSourceRecovered promotes; onSourceStillDown demotes).
                //
                // Previously this loop committed current_source_id the instant
                // ingest->start() returned true — but start() only waits ~3s, and
                // a dead source can accept the TCP connection then 403/timeout a
                // few seconds later, so the channel got parked on a dead URL and
                // (because nextSource() was strictly forward-only) never returned
                // to a working primary. Now we only advance the attempt pointer;
                // promotion to `live` happens solely on actual segment production,
                // and nextFailoverSource() wraps around so every source — including
                //     the working primary — is revisited.
                //
                // failoverCandidate() retries the SAME source when it was
                // producing segments recently (transient blip), and only
                // advances via nextFailoverSource() once live.m3u8 has gone
                // stale — so a good primary is not flapped onto dead backups.
                $candidate = $this->failoverCandidate($channel);
                if (! $candidate) {
                    $channel->update([
                        'source_live'   => false,
                        'stream_status' => 'offline',
                        'pid'           => null,
                    ]);

                    return false;
                }

                try {
                    $channel->update([
                        'current_source_id' => $candidate->id,
                        'source_url'        => $candidate->source_url,
                        'source_type'       => $candidate->source_type,
                        'stream_status'     => 'starting',
                        'source_live'       => false,
                    ]);
                    // cleanSegments=false preserves live.m3u8 so push keeps a
                    // playlist to read while we wait for confirmation.
                    $this->ingest->start($channel, cleanSegments: false);
                    $this->log($channel, 'info', 'source_refresh_pending',
                        "Refresh: trying source [{$candidate->id}]: {$candidate->source_url} (verification deferred to next tick)");

                    return true;
                } catch (\Throwable $e) {
                    $candidate->update(['last_error' => $e->getMessage()]);
                    $this->log($channel, 'error', 'source_refresh_failed',
                        "Source [{$candidate->id}] failed to start: " . $e->getMessage());
                    // Advance past the dead candidate so the next attempt tries
                    // the following source (wrap-around — never re-tries it).
                    $following = $this->nextFailoverSource($channel->fresh());
                    if ($following) {
                        $channel->update([
                            'current_source_id' => $following->id,
                            'source_url'        => $following->source_url,
                            'source_type'       => $following->source_type,
                        ]);
                    }

                    return false;
                }
            } else {
                // Single-source channel: restart the same source. The next
                // monitor tick confirms via onSourceRecovered / onSourceStillDown.
                try {
                    $this->ingest->start($channel, cleanSegments: false);
                    $channel->update([
                        'stream_status' => 'starting',
                        'source_live' => false,
                    ]);
                    $this->log($channel, 'info', 'source_refresh_pending',
                        'Ingest restarted — awaiting segment confirmation on next tick');

                    return true;
                } catch (\Throwable $e) {
                    $this->log($channel, 'error', 'source_refresh_failed',
                        'Refresh failed: ' . $e->getMessage());
                }
            }

            // Failed to confirm any source. Final clean-up to ensure no
            // stale `source_live=true` survives this refresh attempt.
            $channel->update([
                'source_live' => false,
                'stream_status' => 'offline',
                'pid' => null,
                'current_source_id' => null,
                'source_url' => $channel->channelSources()
                    ->where('is_active', true)
                    ->orderBy('priority')
                    ->first()?->source_url
                    ?? $channel->source_url,
            ]);

            return false;
        } catch (\Throwable $e) {
            $this->log($channel, 'error', 'refresh_error', $e->getMessage());
            Log::error("[Channel {$channel->id}] refreshIngest: {$e->getMessage()}");
            // Defensive: any uncaught path must leave DB state clean too.
            $channel->update([
                'source_live' => false,
                'stream_status' => 'offline',
                'pid' => null,
            ]);

            return false;
        }
    }

    /**
     * Next source to attempt during failover, in priority order with wrap-around.
     *
     * Unlike Channel::nextSource() (strictly forward-only, which never returns to
     * a lower-priority working primary once failover has advanced past it), this
     * wraps back to the first active source after the last. That guarantees a
     * working primary is always revisited, so a channel can recover even when
     * every source "ahead" of it in priority order is currently dead.
     */
    private function nextFailoverSource(Channel $channel): ?ChannelSource
    {
        $sources = $channel->channelSources()->where('is_active', true)->orderBy('priority')->get();
        if ($sources->isEmpty()) {
            return null;
        }

        $ids = $sources->pluck('id')->all();
        $pos = $channel->current_source_id ? array_search($channel->current_source_id, $ids) : false;
        if ($pos === false) {
            $pos = -1;
        }

        return $sources->get(($pos + 1) % count($ids));
    }

    /**
     * Decide which source to attempt on a failure.
     *
     * If the channel was producing segments recently (live.m3u8 still fresh),
     * the drop is almost always a transient ffmpeg blip on an otherwise-good
     * source — retrying the SAME source first avoids needlessly flapping onto
     * the dead backups in the failover chain and then spending ~90s cycling
     * back through them. Only when a source has been dead long enough that
     * live.m3u8 has gone stale do we advance to the next source (wrap-around).
     */
    private function failoverCandidate(Channel $channel): ?ChannelSource
    {
        if ($this->wasRecentlyLive($channel)) {
            $same = $channel->current_source_id
                ? $channel->channelSources()->where('id', $channel->current_source_id)->first()
                : null;
            if ($same) {
                return $same;
            }
        }

        return $this->nextFailoverSource($channel);
    }

    private function wasRecentlyLive(Channel $channel, int $seconds = 120): bool
    {
        $live = $channel->dvr_directory . '/live.m3u8';

        return file_exists($live) && (time() - filemtime($live)) <= $seconds;
    }

    public function activateAll(): void
    {
        // Re-activate channels that are not currently confirmed live, including
        // the new 'starting' transitional state introduced by the recovery
        // rewrite. On boot we'd rather rebuild cleanly than trust a stale
        // starting flag from before the restart.
        Channel::where('is_active', true)
            ->whereIn('stream_status', ['idle', 'stopped', 'error', 'offline', 'starting'])
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

        // Primary health signal: is the ingest process running and writing fresh
        // segments? This is a *fast* filesystem check — no network round-trip.
        $ingestRunning = $this->ingest->isRunning($channel);
        $recentSegments = $this->ffmpeg->hasRecentSegments($channel, 20);

        // Segment freshness + ingest-process liveness is the authoritative,
        // NON-BLOCKING health signal for *every* source type. A second ffprobe
        // connection to the upstream is deliberately avoided in the monitor hot
        // path: it added up to ~15 s of blocking per dead source, which stalled
        // the single-threaded monitor loop for minutes across many channels
        // (the root cause of failback recovery taking 10+ minutes in
        // production) and, for IPTV providers, is detected as a duplicate
        // session that kills the ingest. If the ingest ffmpeg is alive and
        // writing recent segments the source is definitively live; if it stops,
        // `recentSegments` goes false within the window and the monitor demotes
        // — no ffprobe required. (checkSourceHealth/probeStream remain available
        // for on-demand diagnostics via the UI/API.)
        $liveM3u8 = $channel->dvr_directory . '/live.m3u8';
        $liveM3u8Fresh = file_exists($liveM3u8)
            && (time() - filemtime($liveM3u8)) <= max(5, (int) $channel->segment_duration * 2);

        // For push-ingest listeners, stale segments from a prior connection can
        // persist ~20 s on disk, so also require that live.m3u8 is being actively
        // updated by the *current* ffmpeg process before declaring recovery.
        $sourceLive = $ingestRunning
            && $recentSegments
            && ($channel->isPushIngest() ? $liveM3u8Fresh : true);

        if ($sourceLive && ! $channel->source_live) {
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
            if ($channel->source_type === 'rtmp') {
                // RTMP push: on_publish starts ffmpeg, on_publish_done lets
                // segments stop. Just mark offline — the on_publish_done
                // callback will restart things when the encoder reconnects.
                $channel->update([
                    'source_live' => false,
                    'stream_status' => 'offline',
                    'dvr_status' => 'idle',
                ]);
                $this->log($channel, 'info', 'encoder_disconnected',
                    'Encoder disconnected from port 1935 — waiting for reconnect');
            } else {
                // SRT push (listener loop): the shell loop keeps ffmpeg restarting
                // automatically after the encoder disconnects — do NOT kill and
                // restart the loop here. Just update state and let the loop handle it.
                $channel->update([
                    'source_live' => false,
                    'stream_status' => 'offline',
                    'dvr_status' => 'idle',
                ]);
                $this->log($channel, 'info', 'listener_waiting',
                    'Encoder disconnected — listener loop waiting for reconnect');
            }
        } else {
            // Pull ingest: stop the failed source.
            $this->ingest->stop($channel);
            $channel->update([
                'source_live' => false,
                'pid' => null,
                'stream_status' => 'offline',
                'dvr_status' => 'idle',
            ]);

            // ── Multi-source failover: try each backup source in turn. ──
            //
            // ingest->start launches ffmpeg and waits three seconds for it
            // to stabilise — a process that's still alive after that window
            // is *promising* but not *confirmed*. The previous code returned
            // immediately after a successful start, skipping the fallback
            // switch entirely on the assumption that "ffmpeg started" equals
            // "source is back". For IPTV/MPEG-TS providers that accept the
            // connection and then return 5xx a few seconds later, this left
            // the channel pointing at a dead live.m3u8 while the dashboard
            // claimed live=1.
            //
            // We now treat every successful start as unconfirmed: we break
            // out of the rotation and let the next monitor tick verify (via
            // onSourceRecovered / onSourceStillDown). And we ALWAYS proceed
            // to switchToFallback below so the push never starves during the
            // verification window.
            if ($channel->hasMultipleSources()) {
                // Failover (wrap-around) — try the next source without committing
                // it as live. Only onSourceRecovered promotes to `live` once real
                // segments are observed, so a dead source that merely passes
                // ffmpeg's 3s start window is no longer parked as the active
                // source. nextFailoverSource() wraps around, so a working primary
                // is always revisited even after failover advanced past it.
                // failoverCandidate() retries the SAME source on a transient blip
                // (live.m3u8 still fresh) and only advances once it has gone stale.
                $candidate = $this->failoverCandidate($channel);
                if ($candidate) {
                    try {
                        $channel->update([
                            'current_source_id' => $candidate->id,
                            'source_url'        => $candidate->source_url,
                            'source_type'       => $candidate->source_type,
                        ]);
                        $this->ingest->start($channel);
                        $this->log($channel, 'info', 'source_switched',
                            "Switched to source [{$candidate->id}]: {$candidate->source_url} (verification deferred to next tick)");
                    } catch (\Throwable $e) {
                        $candidate->update(['last_error' => $e->getMessage()]);
                        $this->log($channel, 'error', 'source_switch_failed',
                            "Source [{$candidate->id}] failed: " . $e->getMessage());
                        // Advance past the dead candidate for the next attempt.
                        $following = $this->nextFailoverSource($channel->fresh());
                        if ($following) {
                            $channel->update([
                                'current_source_id' => $following->id,
                                'source_url'        => $following->source_url,
                                'source_type'       => $following->source_type,
                            ]);
                        }
                    }
                }
            }
        }

        $this->alert->sendOfflineAlert($channel->fresh(), 'Source unreachable', $this->playout->hasFallback($channel));

        // ── Switch to fallback immediately for both push and pull ingest ──
        // switchToFallback generates slate on-demand if no recordings/VOD exist yet.
        if ($this->playout->switchToFallback($channel->fresh())) {
            $channel->update(['playout_status' => 'fallback']);
            $this->log($channel, 'info', 'fallback_activated', 'Playout switched to VOD loop');
            event(new StreamStatusChanged($channel, 'offline'));

            // Restart push and HLS relay so they re-read output.m3u8 from the
            // new symlink target (fallback playlist instead of live).
            $this->restartPushForTransition($channel->fresh());
        } else {
            $this->log($channel, 'error', 'fallback_failed', 'Fallback playout failed to start');
            event(new StreamStatusChanged($channel, 'offline'));
        }
    }

    protected function onSourceRecovered(Channel $channel): void
    {
        if (! $channel->isPushIngest()) {
            // Pull ingest: restart ONLY if the ingest process died.
            // If the ingest is still running and writing fresh segments
            // (the common case for IPTV/HLS flows — refreshIngest started
            // ffmpeg, the source came back, segments started flowing) then
            // restarting it would needlessly tear down a healthy capture
            // and create a brief on-air gap. Only fall through to a restart
            // when ffprobe confirmed the remote is back but our local ffmpeg
            // had already exited (probe-driven recovery for non-IPTV HLS).
            if (! $this->ingest->isRunning($channel)) {
                try {
                    $this->ingest->start($channel);
                } catch (\Throwable $e) {
                    $this->log($channel, 'error', 'ingest_restart_failed', $e->getMessage());

                    return;
                }
            } else {
                $this->log($channel, 'info', 'source_recovered',
                    'Source back online — existing ingest is already capturing segments');
            }
        } else {
            // Push ingest: the encoder reconnected.
            if ($channel->source_type === 'rtmp') {
                // RTMP push: on_publish callback starts the ffmpeg HLS pull.
                // Just log — the ingest is already running from the callback.
                $this->log($channel, 'info', 'source_recovered', 'Encoder reconnected — on_publish started ingest');
            } else {
                // SRT push (listener): the encoder reconnected to the
                // still-listening ffmpeg. No stop/start needed — the ingest
                // is already receiving the stream and writing segments.
                $this->log($channel, 'info', 'source_recovered', 'Source back online — listener active');
            }
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

        // Restart push and HLS relay so they re-read output.m3u8 from the
        // new symlink target. ffmpeg's HLS demuxer does not reliably follow
        // symlink changes on local files — a fresh process is required.
        $this->restartPushForTransition($channel->fresh());

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
            $this->log($channel, 'info', 'playout_forced_live',
                'output.m3u8 was not pointing to live — corrected');
            // Restart push so it picks up the corrected symlink target.
            $this->restartPushForTransition($fresh);
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

        // Keep last_live_at fresh while the source is confirmed alive so it
        // reflects "the last time we actually observed live segments" rather
        // than the moment ffmpeg was launched. We only persist when it's more
        // than a minute stale to avoid useless write churn on every 3s tick.
        if (! $channel->last_live_at || now()->diffInSeconds($channel->last_live_at) > 60) {
            $channel->update(['last_live_at' => now()]);
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
        // SRT: The loop wrapper restarts ffmpeg automatically — only restart the
        // loop itself if the loop process has completely died (not just because
        // the encoder disconnected, which is normal and handled by the loop).
        // RTMP push: on_publish handles restart — nothing to do here.
        if ($channel->isPushIngest() && $channel->source_type === 'srt') {
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
                $this->restartPushForTransition($channel);
            }
        }

        // ── Fallback watchdog: restart loop if it died (NO retry limit) ──
        if ($channel->playout_status === 'fallback' && ! $this->playout->isFallbackRunning($channel)) {
            $this->log($channel, 'warning', 'fallback_restart', 'Fallback loop died — restarting');
            if ($this->playout->switchToFallback($channel)) {
                $channel->update(['playout_status' => 'fallback', 'stream_status' => 'offline']);
                $this->log($channel, 'info', 'fallback_restarted', 'Fallback loop restarted');
                $this->restartPushForTransition($channel);
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

        // ── Pull ingest stall watchdog ────────────────────────────────────
        // A dead source can accept the TCP connection and then stall (return
        // 5xx a few seconds later, or simply hang). ffmpeg keeps the PROCESS
        // alive while it retries, so it never "dies" — and both the normal
        // death-detection path and the monitor's auto-recovery loop are gated
        // on `! isIngestRunning`, so neither ever fires. Detect an ingest that
        // is alive but has produced no segments for a grace window and fail
        // over to the next source (multi-source) or kill it so the auto-
        // recovery loop restarts it on the same URL (single-source).
        if (! $channel->isPushIngest() && $this->ingest->isRunning($channel)) {
            if (! $this->ffmpeg->hasRecentSegments($channel, 30)) {
                $pid = $channel->pid;
                $age = ($pid > 0 && file_exists("/proc/{$pid}"))
                    ? (time() - (int) filemtime("/proc/{$pid}"))
                    : 9999;

                if ($age >= 30) {
                    if ($channel->hasMultipleSources()) {
                        $candidate = $this->failoverCandidate($channel);
                        if ($candidate) {
                            $this->ingest->stop($channel);
                            $channel->update([
                                'current_source_id' => $candidate->id,
                                'source_url'        => $candidate->source_url,
                                'source_type'       => $candidate->source_type,
                            ]);
                            $this->ingest->start($channel);
                            $this->log($channel, 'info', 'source_switched',
                                "Stalled ingest — failing over to source [{$candidate->id}]: {$candidate->source_url} (verification deferred)");
                        } else {
                            $this->ingest->stop($channel);
                        }
                    } else {
                        $this->ingest->stop($channel);
                        $channel->update(['pid' => null]);
                        $this->log($channel, 'warning', 'ingest_stalled',
                            'Ingest process alive but no segments for 30s — killed; auto-recovery will restart');
                    }
                }
            }
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
        exec('ps aux | grep -F ' . escapeshellarg($rtmpUrl) . " | grep -F 'ffmpeg' | grep -v grep | awk '{print \$2}' 2>/dev/null", $lines);

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
        exec('ps aux | grep -F ' . escapeshellarg($rtmpUrl) . " | grep -F 'ffmpeg' | grep -v grep | awk '{print \$2}' 2>/dev/null", $lines);

        return array_values(array_filter(array_map('intval', $lines)));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Restart push and HLS relay after a playout mode transition
     * (live → fallback or fallback → live).
     *
     * ffmpeg's HLS demuxer does not reliably follow symlink changes on
     * local files — the running process keeps reading the old resolved path.
     * A fresh process re-opens output.m3u8 and follows the new symlink.
     *
     * Each channel is independent: only the given channel's processes are
     * affected. Other channels continue running undisturbed.
     */
    private function restartPushForTransition(Channel $channel): void
    {
        // NOTE: The push process reads output.m3u8, which is a symlink that the
        // playout module swaps atomically between live.m3u8 and playout_a.m3u8.
        // ffmpeg's HLS demuxer re-resolves that symlink on every playlist reload,
        // so the running push follows the transition automatically WITHOUT a
        // restart. Force-restarting it here dropped the external RTMP connection
        // on every live<->fallback flap (multiple times per minute), making the
        // relayed stream choppy. We now leave the push running; if it ever truly
        // dies, the per-tick PushService::ensureRunning() watchdog revives it.
        $this->stopHlsRelay($channel);
        $this->startHlsRelay($channel);
    }

    /**
     * True when the ingest ffmpeg process (push listener loop or pull
     * capture) is alive. Used by the monitor command to decide whether
     * a "starting" channel is mid-verification (don't disturb) or fully
     * dead (safe to refresh again).
     */
    public function isIngestRunning(Channel $channel): bool
    {
        return $this->ingest->isRunning($channel);
    }

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
