<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use App\Models\PlaylistItem;
use App\Services\YouTubeMetadataService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * TvPlayoutEngine — manages TV playout channels that run entirely on the VPS.
 *
 * FFmpeg reads a concat playlist file and outputs HLS segments.
 * A separate CG (Character Generator) ffmpeg process applies overlays
 * (logo, ticker, clock, lowerthird) reading from the raw HLS output.
 * A third push ffmpeg reads the branded HLS and pushes to RTMP/SRT.
 *
 * Architecture (4 independent parts):
 *   playlist_items (DB) → concat.txt
 *       → [1. Playout FFmpeg] stream-copy → raw.m3u8  (NEVER restarts except admin stop)
 *           → [2. CG FFmpeg] drawtext+logo → branded.m3u8  (restarts only on overlay change)
 *               → [3. Push FFmpeg] → RTMP/SRT  (restarts only if push dies)
 *                   → [4. nginx HLS] → viewers
 *
 * Media Manager (VOD library) is completely separate — adding/removing media
 * never touches any playout process.
 *
 * MediaMTX is NOT used. nginx serves HLS directly from the channel's dvr_directory.
 */
class TvPlayoutEngine
{
    public function __construct(
        protected FFmpegService $ffmpeg,
    ) {}

    /**
     * Short-lived URL liveness cache (URL → [checked_at, alive]).
     * Prevents repeated synchronous curl checks every time the concat file
     * is rebuilt (add item, reorder, job completion...) which used to make
     * start/rebuild feel slow and heavy for URL-heavy playlists.
     */
    private static array $urlHealth = [];

    private const URL_CHECK_TTL = 300;

    /**
     * Start the TV playout engine for a channel.
     *
     * 4 independent parts:
     *   1. Playout FFmpeg  — concat.txt → stream-copy → raw.m3u8  (PID: tv_playout)
     *      NEVER restarts except when admin explicitly stops the channel.
     *   2. CG FFmpeg       — raw.m3u8 → drawtext+logo+clock+lowerthird → branded.m3u8 (PID: cg_playout)
     *      Restarts ONLY when an overlay setting changes. Fast ~1s restart.
     *   3. Push FFmpeg     — branded.m3u8 → RTMP/SRT (PID: push)
     *      Restarts ONLY if push dies. Reads branded HLS.
     *   4. nginx HLS       — serves branded.m3u8 from dvr_directory to viewers.
     *      MediaMTX is NOT used.
     */
    public function start(Channel $channel): bool
    {
        if ($channel->source_type !== 'tv_playout') {
            return false;
        }

        $dvrDir = $channel->dvr_directory;
        if (! is_dir($dvrDir)) {
            mkdir($dvrDir, 0755, true);
        }

        // Ensure CG directory exists
        $cgDir = $this->cgDirectory($channel);
        if (! is_dir($cgDir)) {
            mkdir($cgDir, 0755, true);
        }

        // Write initial CG files
        $this->writeTickerFile($channel);
        $this->writeMetaFile($channel);
        $this->updateLogoSymlink($channel);

        // Build the concat playlist file
        $concatFile = $this->buildConcatFile($channel);
        if ($concatFile === null) {
            Log::error("[TvPlayout] {$channel->name}: no playlist items to play");
            return false;
        }

        // ── Part 1: Playout FFmpeg (raw HLS, NO overlays, stream copy) ──
        // 1a. If a playout process is already tracked & running, stop it FIRST
        //     so we never end up with two writers on the same DVR dir
        //     (that saturates CPU and freezes the output).
        $playoutPidFile = $this->ffmpeg->pidFile($channel, 'tv_playout');
        $existingPid    = $this->ffmpeg->readPid($playoutPidFile);
        if ($existingPid > 0 && $this->ffmpeg->isRunning($existingPid)) {
            Log::info("[TvPlayout] {$channel->name}: stopping existing playout PID {$existingPid} before restart");
            $this->ffmpeg->stopProcess($existingPid);
        }
        $this->ffmpeg->clearPid($playoutPidFile);

        // 1b. Kill any ORPHANED ffmpeg still writing to this channel's DVR dir
        //     from a previous deployment (e.g. old combined playout writing
        //     live.m3u8/tv_seg_*.ts). Those leak CPU and make the stream freeze.
        //     We keep only our own tracked playout/CG/push PIDs.
        $this->killOrphanedPlayouts($channel);
        $this->cleanStaleHls($channel);

        $resumeOffset = $this->computeResumeOffset($channel);
        $playoutCmd   = $this->buildPlayoutCommand($channel, $concatFile, $resumeOffset);
        $playoutPidFile = $this->ffmpeg->pidFile($channel, 'tv_playout');
        $playoutLogFile = $this->ffmpeg->logFile($channel, 'tv_playout');

        try {
            $playoutPid = $this->ffmpeg->startProcess($playoutCmd, $playoutPidFile, $playoutLogFile, 2);
        } catch (\Throwable $e) {
            $playoutPid = $this->ffmpeg->readPid($playoutPidFile);
            if ($playoutPid <= 0 || ! $this->ffmpeg->isRunning($playoutPid)) {
                Log::error("[TvPlayout] {$channel->name} playout failed to start: {$e->getMessage()}");
                $channel->update(['stream_status' => 'error', 'last_error' => substr($e->getMessage(), 0, 500)]);
                return false;
            }
            Log::info("[TvPlayout] {$channel->name} playout started after brief init delay — PID {$playoutPid}");
        }

        // ── Part 2: CG FFmpeg (overlays → branded HLS) ──
        $cgPid = $this->startCg($channel);

        // Make branded.m3u8 available IMMEDIATELY (fallback → raw) so viewers
        // have signal from the very first second, before CG writes its own.
        $this->syncBrandedFallback($channel);

        $channel->update([
            'is_active'              => true,
            'stream_status'          => 'live',
            'playout_status'         => 'live',
            'playout_pid'            => $playoutPid,
            'cg_pid'                 => $cgPid,
            'source_live'            => true,
            'last_live_at'           => $resumeOffset > 0
                ? now()->subSeconds($resumeOffset)
                : ($channel->last_live_at ?? now()),
            'playout_resume_offset'  => null,
        ]);

        if ($resumeOffset > 0) {
            Log::info("[TvPlayout] {$channel->name} started — playout PID {$playoutPid} (resumed at {$resumeOffset}s), CG PID {$cgPid}");
        } else {
            Log::info("[TvPlayout] {$channel->name} started — playout PID {$playoutPid}, CG PID {$cgPid}");
        }

        // Launch the Now Playing writer
        $this->startNowPlayingWriter($channel);

        // Start external push if configured (reads branded HLS)
        if (! empty($channel->push_url)) {
            $this->startPush($channel);
        }

        return true;
    }

    /**
     * Stop the TV playout engine — stops ALL 3 processes.
     */
    public function stop(Channel $channel): void
    {
        $this->stopCg($channel);
        $this->stopClockWriter($channel);
        $this->stopNowPlayingWriter($channel);
        $this->stopPush($channel);

        $pidFile = $this->ffmpeg->pidFile($channel, 'tv_playout');
        $pid = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }
        $this->ffmpeg->clearPid($pidFile);

        $channel->update([
            'is_active' => false,
            'stream_status' => 'stopped',
            'playout_status' => 'stopped',
            'playout_pid' => null,
            'cg_pid' => null,
            'push_pid' => null,
            'push_status' => 'stopped',
            'source_live' => false,
            'playout_resume_offset' => null,
        ]);

        Log::info("[TvPlayout] {$channel->name} stopped (playout + CG + push)");
    }

    /**
     * Start the CG (Character Generator) overlay ffmpeg process.
     * Reads raw.m3u8 → applies logo/ticker/clock/lowerthird → writes branded.m3u8.
     * This process is INDEPENDENT of the playout ffmpeg — it can restart without
     * interrupting the raw stream.
     *
     * Returns the CG process PID, or 0 if CG is not configured.
     */
    public function startCg(Channel $channel): int
    {
        if (! $this->cgIsConfigured($channel)) {
            return 0;
        }

        $dvrDir = $channel->dvr_directory;
        $cgDir  = $this->cgDirectory($channel);
        if (! is_dir($cgDir)) {
            mkdir($cgDir, 0755, true);
        }

        // Wait for raw.m3u8 to exist
        $rawM3u8 = $dvrDir . '/raw.m3u8';
        $waited  = 0;
        while (! file_exists($rawM3u8) && $waited < 15) {
            sleep(1);
            $waited++;
        }
        if (! file_exists($rawM3u8)) {
            Log::warning("[TvPlayout] {$channel->name}: raw.m3u8 not ready, CG deferred");
            return 0;
        }

        $this->stopCg($channel, silent: true);
        $this->writeTickerFile($channel);
        $this->writeMetaFile($channel);
        $this->updateLogoSymlink($channel);

        $cgCmd     = $this->buildCgCommand($channel);
        $cgPidFile = $this->ffmpeg->pidFile($channel, 'cg_playout');
        $cgLogFile = $this->ffmpeg->logFile($channel, 'cg_playout');

        try {
            $pid = $this->ffmpeg->startProcess($cgCmd, $cgPidFile, $cgLogFile, 2);
        } catch (\Throwable $e) {
            Log::error("[TvPlayout] {$channel->name} CG failed to start: {$e->getMessage()}");
            // Never leave viewers without signal — fall back to RAW under branded.m3u8
            $this->syncBrandedFallback($channel);
            return 0;
        }

        $channel->update(['cg_pid' => $pid]);
        Log::info("[TvPlayout] {$channel->name} CG overlay started — PID {$pid} (raw.m3u8 → branded.m3u8)");
        $this->startClockWriter($channel);

        return $pid;
    }

    /**
     * Stop ONLY the CG overlay process. The playout ffmpeg keeps running.
     */
    public function stopCg(Channel $channel, bool $silent = false): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'cg_playout');
        $pid = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }
        $this->ffmpeg->clearPid($pidFile);
        $this->stopClockWriter($channel);
        if (! $silent) {
            Log::info("[TvPlayout] {$channel->name} CG overlay stopped");
        }
    }

    /**
     * Restart ONLY the CG overlay process. The playout ffmpeg is NEVER touched.
     * Called when any overlay setting changes.
     */
    public function restartCg(Channel $channel): void
    {
        if (! $this->cgIsConfigured($channel)) {
            $this->stopCg($channel, silent: true);
            return;
        }

        $this->writeTickerFile($channel);
        $this->writeMetaFile($channel);
        $this->updateLogoSymlink($channel);
        $this->stopCg($channel, silent: true);
        $this->startCg($channel);
    }

    /**
     * Check if CG overlay is configured for this channel.
     */
    private function cgIsConfigured(Channel $channel): bool
    {
        $hasLogo       = ($channel->logo_enabled ?? true)
            && $channel->logoMedia
            && file_exists($channel->logoMedia->filepath);
        $hasTicker     = $channel->ticker_enabled
            && trim((string) ($channel->ticker_text ?? '')) !== ''
            && trim((string) ($channel->ticker_text ?? '')) !== ' ';
        $hasClock      = $channel->clock_enabled ?? true;
        $hasLowerthird = $channel->lowerthird_enabled ?? true;

        return $hasLogo || $hasTicker || $hasClock || $hasLowerthird;
    }

    /**
     * Compute the resume offset for a channel.
     */
    private function computeResumeOffset(Channel $channel): int
    {
        $resumeOffset = (int) ($channel->playout_resume_offset ?? 0);
        if ($resumeOffset === 0 && $channel->last_live_at && $channel->last_live_at->isPast()) {
            $totalDuration = (float) PlaylistItem::where('channel_id', $channel->id)
                ->where('is_active', true)
                ->sum('duration');
            if ($totalDuration > 0) {
                $elapsed = (float) $channel->last_live_at->diffInSeconds(now(), true);
                $resumeOffset = (int) fmod($elapsed, $totalDuration);
            }
        }

        return $resumeOffset;
    }

    /**
     * Kill any ffmpeg process writing to this channel's DVR directory that is
     * NOT one of our tracked processes (tv_playout / cg_playout / push).
     *
     * Prevents orphaned ffmpeg from previous deployments (old combined playout
     * writing live.m3u8 / tv_seg_*.ts) from running alongside the new pipeline —
     * those leak CPU and make the output freeze. Called on every channel start.
     */
    public function killOrphanedPlayouts(Channel $channel): void
    {
        $dvr  = $channel->dvr_directory;
        $keep = [
            (string) $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'tv_playout')),
            (string) $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'cg_playout')),
            (string) $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'push')),
        ];

        $lines = [];
        exec("ps -eo pid,args 2>/dev/null", $lines);
        foreach ($lines as $line) {
            $line = trim($line);
            // Only ffmpeg processes touching THIS channel's DVR directory
            if (! str_contains($line, 'ffmpeg') || ! str_contains($line, $dvr)) {
                continue;
            }
            $pid = (int) preg_replace('/^(\\d+)\\s+.*$/', '$1', $line);
            if ($pid <= 0 || in_array((string) $pid, $keep)) {
                continue;
            }
            // Our own segment writers (raw_%010d / branded_%010d) are never orphans
            if (str_contains($line, 'raw_%010d') || str_contains($line, 'branded_%010d')) {
                continue;
            }
            Log::warning("[TvPlayout] {$channel->name}: killing orphaned ffmpeg PID {$pid} ({$line})");
            exec("kill -9 {$pid} 2>/dev/null");
        }
    }

    /**
     * Remove stale HLS playlists/segments left over from previous deployments
     * (live.m3u8, output.m3u8, tv_seg_*.ts, index.m3u8) so viewers can never
     * latch onto a frozen/stale stream while the new pipeline starts up.
     */
    private function cleanStaleHls(Channel $channel): void
    {
        $dvr = $channel->dvr_directory;
        if (! is_dir($dvr)) {
            return;
        }

        foreach (['live.m3u8', 'output.m3u8', 'live2.m3u8', 'index.m3u8'] as $name) {
            $f = "{$dvr}/{$name}";
            if (file_exists($f)) {
                @unlink($f);
            }
        }

        foreach (glob("{$dvr}/tv_seg_*.ts") ?: [] as $f) { @unlink($f); }
        foreach (glob("{$dvr}/live_*.ts") ?: [] as $f)   { @unlink($f); }
        foreach (glob("{$dvr}/output_*.ts") ?: [] as $f) { @unlink($f); }
    }

    /**
     * Calculate the current playback offset (seconds into the looping playlist)
     * based on elapsed wall-clock time since last_live_at, modulo total duration.
     * Saves the result into playout_resume_offset so start() can seek to it.
     */
    private function captureOffset(Channel $channel): void
    {
        if (! $channel->last_live_at) {
            return;
        }

        $totalDuration = (float) PlaylistItem::where('channel_id', $channel->id)
            ->where('is_active', true)
            ->sum('duration');

        if ($totalDuration <= 0) {
            return;
        }

        $elapsed = (float) $channel->last_live_at->diffInSeconds(now(), true);
        // Modulo so we land in the correct position within the current loop cycle
        $offset = (int) round(fmod($elapsed, $totalDuration));

        $channel->update(['playout_resume_offset' => $offset]);
        Log::info("[TvPlayout] {$channel->name} captured resume offset {$offset}s (elapsed {$elapsed}s, total {$totalDuration}s)");
    }

    /**
     * Stop → capture offset → restart at the same position in the playlist.
     * Used by admin-initiated schedule changes (recalculate, anchor updates, loop changes).
     * Restarts ONLY the playout ffmpeg — CG is restarted by start() automatically.
     * The push process is also restarted so it picks up the new HLS output.
     */
    public function restartWithResume(Channel $channel, bool $fromAnchor = false): void
    {
        if (! $this->isRunning($channel)) {
            return;
        }

        // When restarting from a new anchor, skip captureOffset so start()
        // computes -ss from the updated last_live_at instead.
        if (! $fromAnchor) {
            $this->captureOffset($channel);
        }

        // Stop CG first (it will be restarted by start())
        $this->stopCg($channel, silent: true);

        // Stop only the playout ffmpeg (not the push — push will be restarted by start())
        $pidFile = $this->ffmpeg->pidFile($channel, 'tv_playout');
        $pid = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }
        $this->ffmpeg->clearPid($pidFile);

        // Restart — start() will read playout_resume_offset and pass -ss to ffmpeg,
        // then start a new CG process reading from the new raw.m3u8.
        $this->start($channel->fresh());
    }

    /**
     * Check if the playout process is running.
     */
    public function isRunning(Channel $channel): bool
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'tv_playout');
        $pid = $this->ffmpeg->readPid($pidFile);
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    /**
     * Start the external RTMP/SRT push process reading branded.m3u8.
     * Called automatically by start() when push_url is set.
     * Safe to call again if already running (no-op).
     *
     * Push reads branded.m3u8 from the dvr_directory (nginx serves HLS directly,
     * MediaMTX is NOT used).
     */
    public function startPush(Channel $channel): bool
    {
        if (empty($channel->push_url)) {
            return false;
        }

        if ($this->isPushRunning($channel)) {
            return true;
        }

        // branded.m3u8 must exist before push can read it.
        $dvrDir = $channel->dvr_directory;
        $m3u8 = $dvrDir . '/branded.m3u8';
        $waited = 0;
        while (! file_exists($m3u8) && $waited < 10) {
            sleep(1);
            $waited++;
        }

        if (! file_exists($m3u8)) {
            Log::warning("[TvPlayout] {$channel->name}: branded.m3u8 not ready, push deferred");
            return false;
        }

        $cmd = $this->ffmpeg->buildPushCommand($channel, $m3u8);
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $logFile = $this->ffmpeg->logFile($channel, 'push');

        try {
            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile, 2);
        } catch (\Throwable $e) {
            Log::error("[TvPlayout] {$channel->name} push failed to start: {$e->getMessage()}");
            $channel->update(['push_status' => 'error', 'last_error' => substr($e->getMessage(), 0, 500)]);
            return false;
        }

        $channel->update(['push_pid' => $pid, 'push_status' => 'live']);
        Log::info("[TvPlayout] {$channel->name} push started — PID {$pid} → {$channel->push_url} (from branded.m3u8)");

        return true;
    }

    /**
     * Stop the external push process.
     */
    public function stopPush(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $pid = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }
        $this->ffmpeg->clearPid($pidFile);
        Log::info("[TvPlayout] {$channel->name} push stopped");
    }

    /**
     * Check if the external push process is running.
     */
    public function isPushRunning(Channel $channel): bool
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $pid = $this->ffmpeg->readPid($pidFile);
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    /**
     * Watchdog: ensure push is running if it should be.
     * Called from the monitor tick. Uses exponential backoff.
     */
    public function ensurePushRunning(Channel $channel): void
    {
        if (empty($channel->push_url) || ! $this->isRunning($channel)) {
            return;
        }

        if ($this->isPushRunning($channel)) {
            return;
        }

        Log::warning("[TvPlayout] {$channel->name}: push died — restarting");
        $this->startPush($channel);
    }

    /**
     * Ensure the CG overlay process is running.
     * Called by the monitor to auto-restart CG if it dies.
     * The playout ffmpeg is NEVER touched by this.
     */
    public function ensureCgRunning(Channel $channel): void
    {
        if (! $this->isRunning($channel)) {
            return;
        }

        $cgPidFile = $this->ffmpeg->pidFile($channel, 'cg_playout');
        $cgPid = $this->ffmpeg->readPid($cgPidFile);

        if ($cgPid > 0 && $this->ffmpeg->isRunning($cgPid)) {
            return;
        }

        Log::warning("[TvPlayout] {$channel->name}: CG overlay died — restarting");
        $this->restartCg($channel);

        // If CG still isn't producing branded.m3u8, fall back to serving the
        // RAW stream (no overlays) under the branded.m3u8 name so viewers
        // NEVER lose signal even during a CG outage.
        $this->syncBrandedFallback($channel);
    }

    /**
     * Write a fallback branded.m3u8 that points at the RAW (no-overlay) stream
     * whenever the CG process is down or hasn't produced a fresh playlist.
     * Keeps the public /hls/{slug}/branded.m3u8 URL always playing — the
     * "playout never stops" guarantee.
     */
    public function syncBrandedFallback(Channel $channel): void
    {
        $dvr     = $channel->dvr_directory;
        $rawFn   = "{$dvr}/raw.m3u8";
        $branded = "{$dvr}/branded.m3u8";
        if (! file_exists($rawFn)) {
            return;
        }

        // CG alive AND branded.m3u8 fresh → leave it alone.
        $cgPid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'cg_playout'));
        if ($cgPid > 0
            && $this->ffmpeg->isRunning($cgPid)
            && file_exists($branded)
            && time() - filemtime($branded) < 30) {
            return;
        }

        // Recently synced — don't churn the file.
        if (file_exists($branded) && time() - filemtime($branded) < 10) {
            return;
        }

        // Rewrite raw.m3u8 → branded.m3u8: bare segment names become
        // raw/raw_....ts so they resolve relative to {dvr}/ via nginx alias
        // (/hls/{slug}/raw/raw_....ts) or the PHP HlsController fallback.
        $lines = explode("\n", (string) file_get_contents($rawFn));
        foreach ($lines as $k => $line) {
            $seg = trim($line);
            if (preg_match('/^raw_\\d+\\.ts$/', $seg)) {
                $lines[$k] = 'raw/' . $seg;
            }
        }

        file_put_contents($branded, implode("\n", $lines));
        Log::warning("[TvPlayout] {$channel->name}: branded.m3u8 fell back to RAW stream (CG unavailable)");
    }

    /**
     * Rebuild the concat file seamlessly — send SIGUSR1 to the running ffmpeg
     * process so it reloads the concat demuxer without any output gap.
     * Falls back to a full restart only if the process is not running.
     */
    public function rebuild(Channel $channel): bool
    {
        $this->recalculateSchedule($channel);
        $this->writeMetaFile($channel);

        $concatPath = $this->concatFilePath($channel);

        // Snapshot whether the current concat is slate-only before rewriting it
        $wasSlateOnly = false;
        if (file_exists($concatPath)) {
            $existing = file_get_contents($concatPath);
            $wasSlateOnly = str_contains($existing, 'slate.mp4') &&
                            ! preg_match('/^file\s+[^\n]*(?<!slate\.mp4)[^\n]*$/m', $existing);
        }

        // Rewrite the concat file on disk
        $concatFile = $this->buildConcatFile($channel);

        // If buildConcatFile returned a URL (not a local file), restart entirely
        if ($concatFile !== null && str_starts_with($concatFile, 'http')) {
            if ($this->isRunning($channel)) {
                $this->stop($channel);
            }
            return $this->start($channel->fresh());
        }

        if ($this->isRunning($channel)) {
            // If we were playing slate and now have real content, restart so the
            // new content starts immediately rather than waiting for the slate loop
            // to exhaust (which could take hours).
            $newContent = $concatFile ? file_get_contents($concatFile) : '';
            $nowHasReal = $concatFile !== null &&
                          ! (str_contains($newContent, 'slate.mp4') &&
                             ! preg_match('/^file\s+[^\n]*(?<!slate\.mp4)[^\n]*$/m', $newContent));

            if ($wasSlateOnly && $nowHasReal) {
                Log::info("[TvPlayout] {$channel->name}: slate → real content, restarting ffmpeg");
                $this->captureOffset($channel);
                $pidFile = $this->ffmpeg->pidFile($channel, 'tv_playout');
                $pid = $this->ffmpeg->readPid($pidFile);
                if ($pid > 0) $this->ffmpeg->stopProcess($pid);
                $this->ffmpeg->clearPid($pidFile);
                return $this->start($channel->fresh());
            }

            // Signal ffmpeg to reload the concat list — zero gap on air
            $pidFile = $this->ffmpeg->pidFile($channel, 'tv_playout');
            $pid = $this->ffmpeg->readPid($pidFile);
            if ($pid > 0) {
                posix_kill($pid, 10); // SIGUSR1 — signal 10 on Linux
                Log::info("[TvPlayout] {$channel->name} sent SIGUSR1 to PID {$pid} — concat reloaded seamlessly");
                return true;
            }
        }

        if ($channel->is_active) {
            return $this->start($channel->fresh());
        }

        return true;
    }

    /**
     * Update logo position (x:y pixels) — triggers CG restart (playout unaffected).
     */
    public function updateLogoPosition(Channel $channel, string $position): void
    {
        $channel->update(['logo_position' => $position]);
        if ($this->cgIsConfigured($channel)) {
            $this->restartCg($channel);
        }
    }

    /**
     * Update playlist loop count — 0 = auto-fill 24h, N = repeat N times.
     */
    public function updatePlaylistLoop(Channel $channel, int $count): void
    {
        $channel->update(['playlist_loop' => max(0, $count)]);
        if ($this->isRunning($channel)) {
            $this->rebuild($channel);
        }
    }

    /**
     * Update ticker text and rewrite the file on disk.
     */
    public function updateTicker(Channel $channel, string $text): void
    {
        $channel->update(['ticker_text' => $text]);
        $this->writeTickerFile($channel);
        Log::info("[TvPlayout] {$channel->name} ticker updated");
    }

    /**
     * Update logo — swaps the active symlink so ffmpeg picks it up without restart.
     * Deletes the previous ChannelMedia logo record to avoid accumulation.
     */
    public function updateLogo(Channel $channel, ?int $mediaId): void
    {
        // Delete old logo media records for this channel (type=logo)
        if ($channel->logo_media_id && $channel->logo_media_id !== $mediaId) {
            \App\Models\ChannelMedia::where('channel_id', $channel->id)
                ->where('name', 'Logo')
                ->where('id', '!=', $mediaId)
                ->get()
                ->each(function ($m) {
                    @unlink($m->filepath);
                    $m->delete();
                });
        }
        $channel->update(['logo_media_id' => $mediaId]);
        $this->updateLogoSymlink($channel->fresh());
    }

    /**
     * Update logo scale (% of video width, 1–50) — triggers CG restart (playout unaffected).
     */
    public function updateLogoScale(Channel $channel, int $scale): void
    {
        $channel->update(['logo_scale' => max(1, min(50, $scale))]);
        if ($this->cgIsConfigured($channel)) {
            $this->restartCg($channel);
        }
    }

    /**
     * Toggle logo overlay on/off — triggers CG restart (playout unaffected).
     */
    public function toggleLogoEnabled(Channel $channel): void
    {
        $channel->update(['logo_enabled' => ! ($channel->logo_enabled ?? true)]);
        $this->updateLogoSymlink($channel->fresh());
        if ($this->cgIsConfigured($channel)) {
            $this->restartCg($channel);
        }
    }

    /**
     * Update clock settings (position, size, color, timezone) — triggers CG restart (playout unaffected).
     */
    public function updateClockSettings(Channel $channel, array $settings): void
    {
        $update = array_filter([
            'clock_position' => $settings['position'] ?? null,
            'clock_fontsize' => isset($settings['fontsize']) ? max(12, min(72, (int) $settings['fontsize'])) : null,
            'clock_color'    => $settings['color'] ?? null,
            'clock_format'   => $settings['format'] ?? null,
            'clock_enabled'  => isset($settings['enabled']) ? (bool) $settings['enabled'] : null,
            'timezone'       => $settings['timezone'] ?? null,
        ], fn ($v) => $v !== null);

        // X/Y can be 0 so filter separately
        if (array_key_exists('x', $settings)) {
            $update['clock_x'] = $settings['x'] === null ? null : (int) $settings['x'];
        }
        if (array_key_exists('y', $settings)) {
            $update['clock_y'] = $settings['y'] === null ? null : (int) $settings['y'];
        }

        $channel->update($update);

        if ($this->cgIsConfigured($channel)) {
            $this->restartCg($channel);
        }
    }

    /**
     * Update ticker settings — triggers CG restart (playout unaffected).
     */
    public function updateTickerSettings(Channel $channel, array $settings): void
    {
        $channel->update(array_filter([
            'ticker_bg_color' => $settings['bg_color'] ?? null,
            'ticker_bg_opacity' => isset($settings['bg_opacity']) ? max(0, min(100, (int) $settings['bg_opacity'])) : null,
            'ticker_font_size' => isset($settings['font_size']) ? max(10, min(72, (int) $settings['font_size'])) : null,
            'ticker_font_color' => $settings['font_color'] ?? null,
            'ticker_speed' => isset($settings['speed']) ? max(10, min(500, (int) $settings['speed'])) : null,
            'ticker_position' => $settings['position'] ?? null,
        ], fn ($v) => $v !== null));

        if ($this->cgIsConfigured($channel)) {
            $this->restartCg($channel);
        }
    }

    /**
     * Update output resolution — triggers CG restart (playout unaffected).
     */
    public function updateResolution(Channel $channel, string $resolution): void
    {
        $channel->update(['output_resolution' => $resolution]);
        if ($this->cgIsConfigured($channel)) {
            $this->restartCg($channel);
        }
    }

    /**
     * Update NOW PLAYING / lowerthird overlay settings — triggers CG restart (playout unaffected).
     */
    public function updateLowerthirdSettings(Channel $channel, array $settings): void
    {
        $update = array_filter([
            'lowerthird_position'   => $settings['position'] ?? null,
            'lowerthird_fontsize'   => isset($settings['fontsize']) ? max(10, min(72, (int) $settings['fontsize'])) : null,
            'lowerthird_font_color' => $settings['font_color'] ?? null,
            'lowerthird_bg_color'   => $settings['bg_color'] ?? null,
            'lowerthird_bg_opacity' => isset($settings['bg_opacity']) ? max(0, min(100, (int) $settings['bg_opacity'])) : null,
            'lowerthird_enabled'    => isset($settings['enabled']) ? (bool) $settings['enabled'] : null,
        ], fn ($v) => $v !== null);

        // X/Y can be 0 so filter separately
        if (array_key_exists('x', $settings)) {
            $update['lowerthird_x'] = $settings['x'] === null ? null : (int) $settings['x'];
        }
        if (array_key_exists('y', $settings)) {
            $update['lowerthird_y'] = $settings['y'] === null ? null : (int) $settings['y'];
        }

        $channel->update($update);

        if ($this->cgIsConfigured($channel)) {
            $this->restartCg($channel);
        }
    }

    /**
     * Recalculate the precise schedule for all playlist items.
     *
     * Anchor priority:
     *   1. Explicit $anchorStartTime (admin override)
     *   2. last_live_at (set when playout starts; preserved after stop so
     *      the schedule stays stable across page loads and restarts)
     *   3. now() — only when the channel has never been started
     */
    public function recalculateSchedule(Channel $channel, ?string $anchorStartTime = null): array
    {
        $items = PlaylistItem::where('channel_id', $channel->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($anchorStartTime) {
            $anchor = Carbon::parse($anchorStartTime);
        } elseif ($channel->last_live_at) {
            $totalDuration = $items->sum('duration');
            if ($totalDuration > 0) {
                // Re-anchor last_live_at so the Now Playing clock stays correct
                // after playlist changes (add/remove/reorder items).
                $elapsed  = (float) $channel->last_live_at->diffInSeconds(now(), true);
                $offset   = fmod($elapsed, $totalDuration);
                $newAnchor = Carbon::now()->subSeconds($offset);
                $channel->update(['last_live_at' => $newAnchor]);
                $anchor = $newAnchor;
            } else {
                $anchor = $channel->last_live_at->copy();
            }
        } else {
            $anchor = Carbon::now();
        }

        $currentTimeTracker = $anchor->copy();
        $totalDuration = 0.0;

        foreach ($items as $item) {
            $start = $currentTimeTracker->copy();

            $wholeSeconds = (int) floor($item->duration);
            $microseconds = (int) (($item->duration - $wholeSeconds) * 1_000_000);

            $end = $start->copy()->addSeconds($wholeSeconds)->addMicroseconds($microseconds);

            $item->update([
                'scheduled_start' => $start,
                'scheduled_end'   => $end,
            ]);

            $totalDuration += $item->duration;
            $currentTimeTracker = $end->copy();
        }

        return [
            'total_duration_seconds' => $totalDuration,
            'formatted_total'        => $this->formatDuration($totalDuration),
            'item_count'             => $items->count(),
            'anchor_start'           => $anchor->toIso8601String(),
            'end_anchor'             => $currentTimeTracker->toIso8601String(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CONCAT FILE BUILDER
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Build the FFmpeg concat playlist text file from database items.
     * Handles both local files and YouTube URLs (via cached stream URLs).
     *
     * Looping: the FULL playlist (media 1 → media N) is repeated in the file —
     * auto-loop fills ~24h, an explicit playlist_loop repeats N times. FFmpeg is
     * never given -stream_loop (it would re-loop only the last file with the
     * concat demuxer), so the whole list always plays in order then restarts.
     */
    private function buildConcatFile(Channel $channel): ?string
    {
        $items = PlaylistItem::where('channel_id', $channel->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        /** @var array<array{path: string, duration: float}> $files */
        $files = [];
        foreach ($items as $item) {
            $resolved = $this->resolveFilePath($item);
            if ($resolved === null) {
                continue;
            }

            // For HTTP URLs: check the URL is alive before including it.
            // Skip the alive check for direct-file URLs (mp4, mkv, etc.) that already
            // have a stored duration — token-based download URLs often fail HEAD/range
            // checks (IP-locking, redirect-only servers) even when ffmpeg can play them.
            if (str_starts_with($resolved, 'http://') || str_starts_with($resolved, 'https://')) {
                $isDirectFileUrl = (bool) preg_match('/\.(mkv|mp4|avi|mov|webm|ts|flv|m4v|wmv|mpg|mpeg)(\?|$)/i', $resolved);
                $hasDuration = (float) $item->duration > 0;
                if (! ($isDirectFileUrl && $hasDuration)) {
                    if (! $this->urlIsAlive($resolved, $channel->name, $item->display_title)) {
                        $item->delete();
                        continue;
                    }
                }
            }

            $duration = (float) $item->duration;
            // HTTP URL items with no duration in DB — probe now and persist.
            if ($duration <= 0 && (str_starts_with($resolved, 'http://') || str_starts_with($resolved, 'https://'))) {
                $ffprobe = trim((string) shell_exec('which ffprobe 2>/dev/null')) ?: 'ffprobe';
                $out = [];
                exec($ffprobe . ' -v quiet -protocol_whitelist file,http,https,tcp,tls,crypto -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg(str_replace(['[', ']'], ['%5B', '%5D'], $resolved)) . ' 2>/dev/null', $out);
                $probed = (float) trim(implode('', $out));
                if ($probed > 0) {
                    $item->update(['duration' => $probed]);
                    $duration = $probed;
                    Log::info("[TvPlayout] Probed duration for item {$item->id}: {$probed}s");
                }
            }
            // Skip items with no playable duration (e.g. images) — including them
            // without a duration hint causes ffmpeg's concat demuxer to hang.
            if ($duration <= 0) {
                Log::warning("[TvPlayout] {$channel->name}: skipping item {$item->id} '{$item->display_title}' — no playable duration");
                continue;
            }
            $files[] = ['path' => $resolved, 'duration' => $duration];
        }

        if ($files === []) {
            // No items resolved yet — fall back to slate.
            $slate = $channel->dvr_directory . '/slate.mp4';
            if (! file_exists($slate) || filesize($slate) < 1024) {
                try {
                    app(\App\Console\Commands\GenerateSlate::class)->generateSlate($channel);
                } catch (\Throwable) {}
            }
            if (file_exists($slate) && filesize($slate) > 1024) {
                $files[] = ['path' => $slate, 'duration' => 0.0];
            } else {
                return null;
            }
        }

        // Total duration of the files ACTUALLY included in this build (skipped items
        // with no playable duration are excluded — counting them here would
        // under-fill the auto-loop).
        $filesDuration = 0.0;
        foreach ($files as $entry) {
            $filesDuration += (float) $entry['duration'];
        }

        $loopCount = (int) ($channel->playlist_loop ?? 0);
        if ($loopCount > 0) {
            // Explicit loop count — full pass-throughs of the whole playlist.
            $repeat = $loopCount;
        } elseif ($filesDuration > 0) {
            // Auto-loop: fill at least 24h of continuous playout by repeating the
            // FULL playlist (media 1 → last → media 1 → …) until the time window
            // is covered. The repeat cap scales with playlist size so the concat
            // file stays bounded, while still filling the full window for typical
            // playlists (e.g. 30s jingles → ~2880 passes → 24h).
            $linesPerPass = max(1, count($files) * 2); // "file X" + "duration Y"
            $maxRepeats   = max(1, (int) floor(20_000 / $linesPerPass));
            $repeat = max(1, min((int) ceil(86400 / $filesDuration), $maxRepeats));
        } else {
            // No duration info (e.g. slate fallback) — repeat conservatively.
            $repeat = 50;
        }

        $concatPath = $this->concatFilePath($channel);

        // Use ffconcat format (with header + duration) — supports both local files and HTTP URLs.
        // The concat demuxer requires duration hints for HTTP URLs to seek/loop correctly.
        $lines = ['ffconcat version 1.0'];
        for ($i = 0; $i < $repeat; $i++) {
            foreach ($files as $entry) {
                $path = $entry['path'];
                // Percent-encode [ and ] in HTTP URLs — ffmpeg's URL parser
                // treats them as range syntax and fails to open the file.
                if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                    $path = str_replace(['[', ']'], ['%5B', '%5D'], $path);
                }
                $lines[] = "file '" . str_replace("'", "'\\''", $path) . "'";
                if ($entry['duration'] > 0) {
                    $lines[] = 'duration ' . number_format($entry['duration'], 3, '.', '');
                }
            }
        }

        file_put_contents($concatPath, implode("\n", $lines));

        return $concatPath;
    }

    /**
     * Check whether an HTTP(S) URL is currently reachable, with a short TTL cache.
     *
     * Repeated synchronous curl checks on every concat rebuild made start/rebuild
     * slow and heavy for URL-heavy playlists. Results are cached per-URL for
     * URL_CHECK_TTL seconds so consecutive rebuilds are instant.
     *
     * The check itself is tolerant: HLS manifests are fetched with a small range
     * request, direct files retry with a GET-range when the server blocks HEAD,
     * and brackets are percent-encoded so CDN token URLs resolve correctly.
     */
    private function urlIsAlive(string $url, string $channelName, string $itemTitle): bool
    {
        $now = time();
        $cached = self::$urlHealth[$url] ?? null;
        if ($cached !== null && ($now - $cached['at']) < self::URL_CHECK_TTL) {
            return (bool) $cached['alive'];
        }

        // Encode brackets — valid in URLs but break some servers / range parsers
        $curlUrl = str_replace(['[', ']'], ['%5B', '%5D'], $url);
        $isHls  = preg_match('/\.(m3u8|m3u|mpd)(\?|$)/i', $url);
        $alive  = false;

        if ($isHls) {
            // HLS: fetch the first 4KB of the manifest
            $code = (int) trim((string) shell_exec(
                'curl -s -o /dev/null -w "%{http_code}" --max-time 10 -L -r 0-4095 ' . escapeshellarg($curlUrl) . ' 2>/dev/null'
            ));
            $alive = $code >= 200 && $code < 400;
        } else {
            // Direct file: HEAD first (fast), fall back to a small GET range
            $code = (int) trim((string) shell_exec(
                'curl -s -o /dev/null -w "%{http_code}" --max-time 5 -L --head ' . escapeshellarg($curlUrl) . ' 2>/dev/null'
            ));
            if ($code < 200 || $code >= 400) {
                $code = (int) trim((string) shell_exec(
                    'curl -s -o /dev/null -w "%{http_code}" --max-time 5 -L -r 0-1023 ' . escapeshellarg($curlUrl) . ' 2>/dev/null'
                ));
            }
            $alive = $code >= 200 && $code < 400;
        }

        // Cache regardless of outcome so a failing URL is only curled once per TTL.
        self::$urlHealth[$url] = ['at' => $now, 'alive' => $alive];

        if (! $alive) {
            Log::warning("[TvPlayout] {$channelName}: URL dead for '{$itemTitle}' (HTTP {$code}) — removing from playlist");
        }

        return $alive;
    }

    /**
     * Resolve a playlist item's filepath to a playable path/URL.
     *
     * - youtube:ID  → resolveYouTubeItem (stream URL extraction + mux)
     * - HLS .m3u8   → pre-transcoded to a local .ts file (HLS never terminates
     *                  in the concat demuxer, so it must be pulled to disk first)
     * - other HTTP  → returned as-is (direct MP4 googlevideo URLs work fine)
     * - local file  → returned as-is
     */
    private function resolveFilePath(PlaylistItem $item): ?string
    {
        $path = $item->filepath;

        if (str_starts_with($path, 'youtube:')) {
            return $this->resolveYouTubeItem($item);
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            // VOD HLS (.m3u8 with a known finite duration) can be passed directly to
            // ffmpeg's concat demuxer — it handles them natively.
            // Only pre-transcode to .ts when the item has no duration (likely a live stream
            // that never terminates and would block the concat demuxer).
            if (str_contains($path, '.m3u8') || str_contains($path, '/hls')) {
                // If we have a duration, treat as VOD and pass directly
                if ((float) $item->duration > 0) {
                    return $path;
                }
                return $this->resolveHlsItem($item);
            }
            return $path;
        }

        if (file_exists($path) && filesize($path) > 1024) {
            return $path;
        }

        return null;
    }

    /**
     * Pre-transcode an HLS stream URL to a local .ts file.
     * Uses -t duration to stop at the right time. Cached for 5 hours.
     * Returns the local .ts path or null if transcoding fails.
     */
    private function resolveHlsItem(PlaylistItem $item): ?string
    {
        $cacheDir = storage_path('app/hls_cache');
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $hash     = md5($item->filepath);
        $tsFile   = "{$cacheDir}/{$hash}.ts";
        $lockFile = "{$cacheDir}/{$hash}.transcoding";
        $isHls    = str_contains($item->filepath, '.m3u8') || str_contains($item->filepath, '/hls');

        // Return cached file if fresh (< 5 hours old)
        if (file_exists($tsFile) && filesize($tsFile) > 1_048_576) {
            if (time() - filemtime($tsFile) < 18000) {
                return $tsFile;
            }
            @unlink($tsFile);
        }

        // Already transcoding in background — return null (slate will play)
        if (file_exists($lockFile) && time() - filemtime($lockFile) < 7200) {
            return null;
        }

        $duration = (float) $item->duration;
        if ($duration <= 0 && $isHls) {
            // Probe duration for HLS streams
            $ffprobe = trim((string) shell_exec('which ffprobe 2>/dev/null')) ?: 'ffprobe';
            $out = [];
            exec($ffprobe . ' -v quiet -protocol_whitelist file,http,https,tcp,tls,crypto -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg(str_replace(['[', ']'], ['%5B', '%5D'], $item->filepath)) . ' 2>/dev/null', $out);
            $duration = (float) trim(implode('', $out));
            if ($duration > 0) {
                $item->update(['duration' => $duration]);
            } else {
                return null;
            }
        }

        Log::info("[TvPlayout] URL item {$item->id}: transcoding queued");
        touch($lockFile);

        \App\Jobs\TranscodeUrlToTs::dispatch(
            $item->filepath, $tsFile, $lockFile, $item->channel_id, $isHls, $duration
        );

        return null; // slate plays while transcoding
    }

    /**
     * Get the download status for a YouTube playlist item.
     * Returns: 'ready', 'downloading', 'queued', or 'failed'.
     */
    public function getYouTubeDownloadStatus(PlaylistItem $item): string
    {
        $videoId = PlaylistItem::parseYouTubeId($item->filepath);
        if ($videoId === null) {
            return 'ready';
        }

        $cacheDir  = storage_path('app/youtube_cache');
        $localFile = "{$cacheDir}/{$videoId}.mp4";
        $lockFile  = "{$cacheDir}/{$videoId}.downloading";
        $logFile   = "{$cacheDir}/{$videoId}.log";

        // Downloaded and ready
        if (file_exists($localFile) && filesize($localFile) > 1_048_576) {
            return 'ready';
        }

        // Download in progress
        if (file_exists($lockFile)) {
            // Check if lock is stale (> 30 min)
            if (time() - filemtime($lockFile) > 1800) {
                @unlink($lockFile);
                return 'failed';
            }
            return 'downloading';
        }

        // Check log for failure
        if (file_exists($logFile)) {
            $log = file_get_contents($logFile);
            if (str_contains($log, 'All attempts failed')) {
                return 'failed';
            }
        }

        return 'queued';
    }

    /**
     * Trigger a background download for a YouTube playlist item.
     * Called when an item is added or when stream URL extraction fails.
     */
    public function triggerYouTubeDownload(PlaylistItem $item): void
    {
        $videoId = PlaylistItem::parseYouTubeId($item->filepath);
        if ($videoId === null) {
            return;
        }

        $cacheDir  = storage_path('app/youtube_cache');
        $lockFile  = "{$cacheDir}/{$videoId}.downloading";
        $localFile = "{$cacheDir}/{$videoId}.mp4";

        // Already downloaded
        if (file_exists($localFile) && filesize($localFile) > 1_048_576) {
            return;
        }

        // Already downloading
        if (file_exists($lockFile)) {
            return;
        }

        $this->startBackgroundDownload($videoId, $item);
    }

    /**
     * Resolve a YouTube playlist item to a playable path.
     *
     * Priority:
     *   1. Local downloaded .mp4 (permanent)
     *   2. Cached stream URL(s) from yt-dlp -g (expires ~6h, re-extracted when stale)
     *      - Single URL  → returned directly (muxed stream)
     *      - Two URLs    → pre-muxed into a local .ts file via ffmpeg, path returned
     *   3. Background download triggered as fallback
     */
    private function resolveYouTubeItem(PlaylistItem $item): ?string
    {
        $videoId = PlaylistItem::parseYouTubeId($item->filepath);
        if ($videoId === null) {
            return null;
        }

        $cacheDir = storage_path('app/youtube_cache');
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $localFile   = "{$cacheDir}/{$videoId}.mp4";
        $lockFile    = "{$cacheDir}/{$videoId}.downloading";
        $urlCacheFile = "{$cacheDir}/{$videoId}.stream_url";
        $muxedTs     = "{$cacheDir}/{$videoId}_muxed.ts";

        // 1. Local downloaded .mp4
        if (file_exists($localFile) && filesize($localFile) > 1_048_576) {
            return $localFile;
        }

        // 2. Cached stream URL(s) — check expiry
        if (file_exists($urlCacheFile)) {
            $cached = trim((string) file_get_contents($urlCacheFile));
            if (strlen($cached) > 10) {
                $urls = explode("\n", $cached);
                $videoUrl = trim($urls[0]);
                $audioUrl = isset($urls[1]) ? trim($urls[1]) : null;

                // Check expiry of the video URL
                $expiresAt = $this->getStreamUrlExpiry($videoUrl);
                $age = time() - filemtime($urlCacheFile);
                $valid = $expiresAt !== null ? time() < ($expiresAt - 600) : $age < 7200;

                if ($valid) {
                    if ($audioUrl === null) {
                        return $videoUrl; // muxed single stream
                    }
                    // Two separate streams — pre-mux into a .ts file
                    return $this->muxVideoAudio($videoId, $videoUrl, $audioUrl, $muxedTs, (float) $item->duration);
                }
            }
            // Stale — delete and re-extract
            @unlink($urlCacheFile);
            @unlink($muxedTs);
        }

        // 3. Kick off background extraction+mux and return null (slate plays while working)
        $this->startBackgroundExtract($videoId, $item, $urlCacheFile, $muxedTs);
        return null;
    }

    /**
     * Pre-mux separate video+audio URLs into a local .ts file using ffmpeg.
     * This runs synchronously but is fast (no re-encode — copy streams).
     * Returns the muxed .ts path on success, null on failure.
     */
    private function muxVideoAudio(string $videoId, string $videoUrl, string $audioUrl, string $outputTs, float $duration): ?string
    {
        // Return cached mux if still fresh
        if (file_exists($outputTs) && filesize($outputTs) > 1_048_576) {
            if (time() - filemtime($outputTs) < 18000) {
                return $outputTs;
            }
            @unlink($outputTs);
        }

        $lockFile = $outputTs . '.muxing';
        if (file_exists($lockFile) && time() - filemtime($lockFile) < 7200) {
            return null;
        }
        touch($lockFile);

        $channelId = $this->getChannelIdForVideoId($videoId);
        \App\Jobs\MuxVideoAudio::dispatch($videoId, $videoUrl, $audioUrl, $outputTs, $channelId ?? 0);

        Log::info("[TvPlayout] YouTube {$videoId}: mux job queued");
        return null;
    }

    private function getChannelIdForVideoId(string $videoId): ?int
    {
        $item = PlaylistItem::where('filepath', 'youtube:' . $videoId)->first();
        return $item?->channel_id;
    }

    /**
     * Background: extract stream URL(s) via yt-dlp -g, then mux if needed,
     * then trigger tv:rebuild-concat. Slate plays until this completes.
     */
    private function startBackgroundExtract(string $videoId, PlaylistItem $item, string $urlCacheFile, string $muxedTs): void
    {
        $lockFile = $urlCacheFile . '.extracting';
        if (file_exists($lockFile) && time() - filemtime($lockFile) < 300) {
            return;
        }
        touch($lockFile);

        \App\Jobs\ExtractYouTubeUrl::dispatch($videoId, $item->channel_id, $urlCacheFile, $muxedTs);

        Log::info("[TvPlayout] YouTube {$videoId}: extraction job queued");
    }

    /**
     * Extract direct streaming URL(s) from YouTube using yt-dlp -g.
     */
    private function extractStreamUrl(string $videoId): ?string
    {
        $ytdlp = $this->findYtdlp();
        if ($ytdlp === null) {
            return null;
        }

        $url = "https://www.youtube.com/watch?v={$videoId}";
        $cookieSource = storage_path('app/youtube_cookies_auth.txt');
        $cookieCopy = tempnam(sys_get_temp_dir(), 'yt_cookies_');
        if (file_exists($cookieSource) && filesize($cookieSource) > 50) {
            copy($cookieSource, $cookieCopy);
        } else {
            $cookieCopy = null;
        }

        // Try muxed first (itag 18 = 360p mp4 with audio), then separate streams
        $formats = [
            '18',                                                          // muxed 360p mp4
            '22',                                                          // muxed 720p mp4
            'bestvideo[ext=mp4][height<=1080]+bestaudio[ext=m4a]/bestvideo+bestaudio',
        ];
        $clients = ['tv_embedded', 'web', 'ios'];
        // Only use proxy if it's HTTP/HTTPS — skip broken SOCKS proxies
        $proxy = app(\App\Services\ProxyService::class)->getWorkingProxy();
        if ($proxy && str_starts_with($proxy, 'socks')) {
            $proxy = null;
        }

        try {
            foreach ($formats as $fmt) {
                foreach ($clients as $client) {
                    $cmd = [
                        $ytdlp, '--no-warnings', '-g',
                        '--socket-timeout', '20', '--retries', '1',
                        '--format', $fmt, '--no-playlist',
                        '--extractor-args', "youtube:player_client={$client}",
                    ];
                    if ($cookieCopy !== null) { $cmd[] = '--cookies'; $cmd[] = $cookieCopy; }
                    if ($proxy) { $cmd[] = '--proxy'; $cmd[] = $proxy; }
                    $cmd[] = $url;

                    $output = []; $exitCode = 0;
                    exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>/dev/null', $output, $exitCode);

                    $lines = array_filter(array_map('trim', $output), fn ($l) => str_starts_with($l, 'http'));
                    if ($exitCode === 0 && count($lines) >= 1) {
                        $result = implode("\n", array_values($lines)); // 1 line = muxed, 2 lines = video+audio
                        Log::info("[TvPlayout] YouTube {$videoId}: extracted " . count($lines) . " URL(s) via client={$client} fmt={$fmt}");
                        return $result;
                    }
                }
            }
        } finally {
            if ($cookieCopy !== null && file_exists($cookieCopy)) {
                @unlink($cookieCopy);
            }
        }

        return null;
    }

    private function findYtdlp(): ?string
    {
        foreach (['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp'] as $p) {
            if (is_executable($p)) return $p;
        }
        $found = trim((string) shell_exec('which yt-dlp 2>/dev/null'));
        return $found !== '' ? $found : null;
    }

    private function getStreamUrlExpiry(string $url): ?int
    {
        if (preg_match('/[?&]expire=(\d+)/', $url, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Start a background shell process that downloads the YouTube video,
     * then rebuilds the concat file when done.
     * Tries direct first (cookies work without proxy), then proxy as fallback.
     */
    private function startBackgroundDownload(string $videoId, PlaylistItem $item): void
    {
        $ytdlp = $this->findYtdlp();
        if ($ytdlp === null) {
            Log::error('[TvPlayout] yt-dlp not found — cannot download YouTube video');
            return;
        }

        $cacheDir  = storage_path('app/youtube_cache');
        $localFile = "{$cacheDir}/{$videoId}.mp4";
        $lockFile  = "{$cacheDir}/{$videoId}.downloading";
        $logFile   = "{$cacheDir}/{$videoId}.log";
        $url       = "https://www.youtube.com/watch?v={$videoId}";
        $channelId = $item->channel_id;
        $artisan   = base_path('artisan');
        $tmpPattern = $localFile . '.tmp.%(ext)s';
        $path      = '/usr/local/bin:/usr/bin:/bin';

        // Copy global cookies so yt-dlp can't overwrite the source
        $cookieSource = storage_path('app/youtube_cookies_auth.txt');
        $cookieCopy = tempnam(sys_get_temp_dir(), 'yt_dl_cookies_');
        if (file_exists($cookieSource) && filesize($cookieSource) > 50) {
            copy($cookieSource, $cookieCopy);
        } else {
            $cookieCopy = null;
        }

        $proxy     = app(\App\Services\ProxyService::class)->getWorkingProxy() ?: '';
        $clients   = ['android', 'web', 'web_safari', 'ios'];
        $cookieArg = ($cookieCopy !== null) ? '--cookies ' . escapeshellarg($cookieCopy) : '';
        $proxyArg  = ($proxy !== '') ? '--proxy ' . escapeshellarg($proxy) : '';

        $clientAttempts = '';

        // Round 1: Try each client WITHOUT proxy
        foreach ($clients as $i => $client) {
            $attemptCmd = $ytdlp
                . ' --js-runtimes node --no-warnings --socket-timeout 30'
                . ' --retries 2'
                . ' --format "bestvideo[ext=mp4][height<=1080]+bestaudio[ext=m4a]/best[ext=mp4]/best"'
                . ' --merge-output-format mp4'
                . ' --no-playlist'
                . " --extractor-args youtube:player_client={$client}"
                . ' --output ' . escapeshellarg($tmpPattern)
                . ' ' . $cookieArg
                . ' ' . escapeshellarg($url);

            if ($i === 0) {
                $clientAttempts .= "echo \"[yt-dlp] Trying client={$client} (direct)\" >> " . escapeshellarg($logFile) . "\n";
                $clientAttempts .= "{$attemptCmd} >> " . escapeshellarg($logFile) . " 2>&1\n";
            } else {
                $clientAttempts .= "if [ ! -f " . escapeshellarg($localFile) . " ]; then\n";
                $clientAttempts .= "  echo \"[yt-dlp] Trying client={$client} (direct)\" >> " . escapeshellarg($logFile) . "\n";
                $clientAttempts .= "  {$attemptCmd} >> " . escapeshellarg($logFile) . " 2>&1\n";
                $clientAttempts .= "fi\n";
            }
        }

        // Round 2: If all direct attempts failed and we have a proxy, try with proxy
        if ($proxy !== '') {
            foreach ($clients as $client) {
                $attemptCmd = $ytdlp
                    . ' --js-runtimes node --no-warnings --socket-timeout 30'
                    . ' --retries 2'
                    . ' --format "bestvideo[ext=mp4][height<=1080]+bestaudio[ext=m4a]/best[ext=mp4]/best"'
                    . ' --merge-output-format mp4'
                    . ' --no-playlist'
                    . " --extractor-args youtube:player_client={$client}"
                    . ' --output ' . escapeshellarg($tmpPattern)
                    . ' ' . $cookieArg
                    . ' ' . $proxyArg
                    . ' ' . escapeshellarg($url);

                $clientAttempts .= "if [ ! -f " . escapeshellarg($localFile) . " ]; then\n";
                $clientAttempts .= "  echo \"[yt-dlp] Trying client={$client} (proxy)\" >> " . escapeshellarg($logFile) . "\n";
                $clientAttempts .= "  {$attemptCmd} >> " . escapeshellarg($logFile) . " 2>&1\n";
                $clientAttempts .= "fi\n";
            }
        }

        // After all attempts, move file and rebuild concat
        $postDownload = ""
            . "DLFILE=\$(ls " . escapeshellarg($localFile . '.tmp.*') . " 2>/dev/null | head -1)\n"
            . "if [ -n \"\$DLFILE\" ] && [ -s \"\$DLFILE\" ]; then\n"
            . "  mv \"\$DLFILE\" " . escapeshellarg($localFile) . "\n"
            . "  echo \"[yt-dlp] Download complete: " . escapeshellarg($localFile) . "\" >> " . escapeshellarg($logFile) . "\n"
            . "else\n"
            . "  echo \"[yt-dlp] All attempts failed\" >> " . escapeshellarg($logFile) . "\n"
            . "fi\n"
            . "rm -f " . escapeshellarg($lockFile) . "\n"
            . "rm -f " . escapeshellarg($cookieCopy ?? '') . "\n"
            . "php " . escapeshellarg($artisan) . " tv:rebuild-concat " . escapeshellarg((string) $channelId) . " 2>/dev/null || true";

        $script = "#!/bin/sh\n"
            . "export PATH={$path}:\$PATH\n"
            . "echo '[yt-dlp] Starting download for {$videoId}' > " . escapeshellarg($logFile) . "\n"
            . "touch " . escapeshellarg($lockFile) . "\n"
            . $clientAttempts
            . $postDownload;

        $scriptFile = "{$cacheDir}/{$videoId}.sh";
        file_put_contents($scriptFile, $script);
        chmod($scriptFile, 0755);

        shell_exec("setsid sh " . escapeshellarg($scriptFile) . " </dev/null >/dev/null 2>&1 &");

        Log::info("[TvPlayout] Started background download for YouTube {$videoId} (channel {$channelId}) — log: {$logFile}");
    }

    /**
     * Download a YouTube video to a local file via yt-dlp.
     * Returns true on success.
     */

    private function updateItemDurationFromFile(PlaylistItem $item, string $path): void
    {
        try {
            $ffprobe = trim((string) shell_exec('export PATH=/usr/local/bin:/usr/bin:/bin:$PATH; which ffprobe 2>/dev/null'));
            if ($ffprobe === '') $ffprobe = 'ffprobe';
            $out = [];
            exec('export PATH=/usr/local/bin:/usr/bin:/bin:$PATH; ' . $ffprobe . ' -v quiet -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($path) . ' 2>/dev/null', $out);
            $duration = (float) trim(implode('', $out));
            if ($duration > 0) {
                $item->update(['duration' => $duration]);
            }
        } catch (\Throwable) {}
    }

    private function getYouTubeCookiePath(PlaylistItem $item): ?string
    {
        // Prefer channel-level cookies if they contain auth fields
        $cookies = $item->channel->youtube_cookies ?? '';
        if (strlen($cookies) > 50 && str_contains($cookies, 'LOGIN_INFO')) {
            // Check if PSIDTS cookies are expired (they expire ~2 weeks after creation)
            if ($this->areCookiesExpired($cookies)) {
                Log::warning("[TvPlayout] YouTube cookies for channel {$item->channel_id} appear expired — update them in Settings");
            }
            // Write to a "source" file that yt-dlp won't overwrite
            $cookieDir = storage_path('app');
            $sourceFile = "{$cookieDir}/yt_cookies_auth_{$item->channel_id}.txt";
            file_put_contents($sourceFile, trim($cookies));
            return $sourceFile;
        }

        // Fallback to global source file
        $global = storage_path('app/youtube_cookies_auth.txt');
        if (file_exists($global) && filesize($global) > 50) {
            return $global;
        }

        return null;
    }

    /**
     * Check if PSIDTS cookies are expired by looking at their expiry timestamps.
     */
    private function areCookiesExpired(string $cookieText): bool
    {
        // Look for __Secure-1PSIDTS or __Secure-3PSIDTS expiry timestamps
        if (preg_match('/__Secure-[13]PSIDTS\s+\S+\s+\S+\s+\S+\s+(\d+)/', $cookieText, $m)) {
            $expiry = (int) $m[1];
            if ($expiry > 0 && $expiry < time()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Derive a scale factor relative to 1080p so all overlay pixel values
     * (font sizes, margins, border widths, speeds) shrink/grow proportionally
     * with the output resolution. 1920×1080 → 1.0, 1280×720 → 0.667, 854×480 → 0.444.
     */
    private function overlayScale(Channel $channel): float
    {
        $resolution = $channel->output_resolution ?? '1920x1080';
        if ($resolution === 'auto' || ! preg_match('/^(\d+)[x:](\d+)$/', $resolution, $m)) {
            return 1.0;
        }
        // Scale by height relative to 1080 (height drives readability more than width)
        return max(0.1, (int) $m[2] / 1080.0);
    }

    /** Scale an integer pixel value and return as int (minimum 1). */
    private function px(int $value, float $scale): int
    {
        return max(1, (int) round($value * $scale));
    }

    /**
     * Return the RAM-based HLS output directory for a playout channel.
     * Uses /dev/shm (tmpfs) so segment writes never touch disk.
     */
    private function playoutHlsDir(Channel $channel): string
    {
        $dir = '/dev/shm/skymedia/' . $channel->id;
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Playout output directory — raw HLS (no overlays, stream copy).
     */
    private function rawHlsDir(Channel $channel): string
    {
        $dir = $channel->dvr_directory . '/raw';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * CG output directory — branded HLS (with overlays).
     */
    private function brandedHlsDir(Channel $channel): string
    {
        $dir = $channel->dvr_directory . '/branded';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Build the FFmpeg command for Part 1: Playout (raw HLS, stream copy, no overlays).
     *
     * NEVER restarts except when admin explicitly stops the channel.
     * Writes to dvr_directory/raw/ so nginx can serve it.
     */
    private function buildPlayoutCommand(Channel $channel, string $concatFile, int $resumeOffset = 0): array
    {
        $rawDir     = $this->rawHlsDir($channel);
        $segPattern = "{$rawDir}/raw_%010d.ts";
        // PLAYLIST IS FLAT at the DVR root so nginx alias
        //   /hls/{slug}/{file} → {dvr}/{file}
        // and the HlsController resolve it. Segments stay in {dvr}/raw/ and are
        // referenced relative to the playlist (raw/raw_....ts), which the alias
        // also resolves correctly.
        $m3u8Out    = $channel->dvr_directory . '/raw.m3u8';
        $segDur     = 2;

        $cmd = [
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            '-progress', $this->nowPlayingProgressFile($channel),
            '-fflags', '+genpts+igndts+discardcorrupt+flush_packets',
            '-err_detect', 'ignore_err',
            '-re',
            '-safe', '0',
            '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
            '-f', 'concat',
        ];

        if ($resumeOffset > 0) {
            $cmd[] = '-ss';
            $cmd[] = (string) $resumeOffset;
        }

        $cmd[] = '-i';
        $cmd[] = $concatFile;

        // Stream copy — no re-encode, no overlays. Fast and stable.
        $cmd = array_merge($cmd, [
            '-c:v', 'copy',
            '-c:a', 'copy',
            '-f', 'hls',
            '-hls_time', (string) $segDur,
            '-hls_list_size', '3',
            '-hls_flags', 'delete_segments+omit_endlist+append_list',
            '-hls_delete_threshold', '1',
            '-hls_segment_type', 'mpegts',
            '-hls_segment_filename', $segPattern,
            '-hls_allow_cache', '0',
            '-hls_start_number_source', 'epoch',
            '-max_muxing_queue_size', '4096',
            $m3u8Out,
        ]);

        return $cmd;
    }

    /**
     * Build the FFmpeg command for Part 2: CG overlay (raw → branded HLS with overlays).
     *
     * Input 0: raw.m3u8 (from playout ffmpeg)
     * Filter chain: [logo overlay] → [ticker drawtext] → [clock drawtext] → [lowerthird]
     * Output:  branded.m3u8 HLS segments (H.264 re-encode with overlays)
     *
     * This process restarts ONLY when an overlay setting changes.
     * Reads from dvr_directory/raw/ and writes to dvr_directory/branded/.
     * nginx serves branded.m3u8 to viewers. MediaMTX is NOT used.
     */
    private function buildCgCommand(Channel $channel): array
    {
        $rawDir     = $this->rawHlsDir($channel);
        $brandedDir = $this->brandedHlsDir($channel);
        // Both playlists live FLAT at the DVR root (nginx alias + HlsController
        // resolve /hls/{slug}/{file} → {dvr}/{file}); segments live in
        // {dvr}/raw/ and {dvr}/branded/ and are referenced relative to their
        // playlists, which the alias resolves too.
        $rawM3u8    = $channel->dvr_directory . '/raw.m3u8';
        $segPattern = "{$brandedDir}/branded_%010d.ts";
        $m3u8Out    = $channel->dvr_directory . '/branded.m3u8';
        $segDur     = 2;

        // Scale factor for all overlay pixel values relative to 1080p baseline
        $s = $this->overlayScale($channel);

        $cmd = [
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            // Write machine-readable progress (out_time_us etc.) so the Now Playing
            // overlay can be derived from ACTUAL playback instead of wall-clock.
            '-progress', $this->nowPlayingProgressFile($channel),
            '-fflags', '+genpts+igndts+discardcorrupt+flush_packets',
            '-err_detect', 'ignore_err',
        ];

        // Looping is handled INSIDE the concat file itself — the whole playlist
        // (file1 → file2 → … → fileN) is repeated $repeat times below.
        //
        // -stream_loop is deliberately NOT used: with the concat demuxer it loops
        // at the stream level after the list ends, which re-reads only the LAST
        // file — making playback appear stuck on a single media item. Repeating
        // the full list guarantees the loop always restarts at media 1.
        $cmd[] = '-re';

        // HLS input from playout ffmpeg (raw.m3u8)
        $cmd[] = '-re';
        $cmd[] = '-safe';
        $cmd[] = '0';
        $cmd[] = '-protocol_whitelist';
        $cmd[] = 'file,http,https,tcp,tls,crypto';
        $cmd[] = '-f';
        $cmd[] = 'hls';
        $cmd[] = '-hls_time';
        $cmd[] = '2';
        $cmd[] = '-hls_list_size';
        $cmd[] = '3';
        $cmd[] = '-hls_start_number_source';
        $cmd[] = 'epoch';
        $cmd[] = '-i';
        $cmd[] = $rawM3u8;

        // Logo overlay — only include when logo_enabled is true.
        $this->ensureLogoBlank($channel);
        $logoActivePath = $this->logoActivePath($channel);
        $scalePct = max(1, min(50, (int) ($channel->logo_scale ?? 12)));
        $logoEnabled = $channel->logo_enabled ?? true;

        // logo_position stored as "x:y" pixels (designed at 1080p) — scale to output.
        $position = $channel->logo_position ?? '20:20';
        if (preg_match('/^(-?\d+):(-?\d+)$/', $position, $m)) {
            $px = (int) round((int) $m[1] * $s);
            $py = (int) round((int) $m[2] * $s);
            $ox = $px < 0 ? "W-w{$px}" : (string) $px;
            $oy = $py < 0 ? "H-h{$py}" : (string) $py;
            $overlayPos = "{$ox}:{$oy}";
        } else {
            $margin = $this->px(20, $s);
            $overlayPos = match ($position) {
                'top-left'     => "{$margin}:{$margin}",
                'bottom-left'  => "{$margin}:H-h-{$margin}",
                'bottom-right' => "W-w-{$margin}:H-h-{$margin}",
                default        => "W-w-{$margin}:{$margin}",
            };
        }

        // Build filter_complex
        $filterParts = [];
        $lastLabel = '0:v';
        $inputIndex = 1; // 0 is concat input

        // Output resolution scaling (applied first so overlays render at target size)
        $resolution = $channel->output_resolution ?? '1920x1080';
        if ($resolution !== 'auto' && preg_match('/^(\d+)[x:](\d+)$/', $resolution, $rm)) {
            $filterParts[] = "[{$lastLabel}]scale={$rm[1]}:{$rm[2]}:flags=lanczos,setsar=1[resolved]";
            $lastLabel = 'resolved';
        }

        if ($logoEnabled) {
            $cmd = array_merge($cmd, ['-loop', '1', '-i', $logoActivePath]);
            $filterParts[] = "[{$inputIndex}:v]scale=iw*{$scalePct}/100:-1[logo_scaled]";
            $filterParts[] = "[{$lastLabel}][logo_scaled]overlay={$overlayPos}[with_logo]";
            $lastLabel = 'with_logo';
            $inputIndex++;
        }

        // Ticker (scrolling text)
        $tickerText = trim((string) $channel->ticker_text);
        if ($channel->ticker_enabled && $tickerText !== '') {
            $cgDir = $this->cgDirectory($channel);
            $tickerItems = $channel->ticker_items ?? [];

            $tickerSpeed    = $this->px(max(10, min(500, (int) ($channel->ticker_speed ?? 80))), $s);
            $tickerFontSize = $this->px(max(10, min(72,  (int) ($channel->ticker_font_size ?? 24))), $s);
            $tickerBorderW  = $this->px(8, $s);
            $tickerMargin   = $this->px(10, $s);
            $tickerFontColor = $channel->ticker_font_color ?? 'white';
            $tickerBgColor   = $channel->ticker_bg_color ?? '#000000';
            $tickerBgOpacity = max(0, min(100, (int) ($channel->ticker_bg_opacity ?? 65)));
            $tickerBgOpacityFp = number_format($tickerBgOpacity / 100, 2, '.', '');
            $tickerPos = $channel->ticker_position ?? 'bottom';

            $bgHex = ltrim($tickerBgColor, '#');
            if (strlen($bgHex) === 3) {
                $bgHex = $bgHex[0].$bgHex[0].$bgHex[1].$bgHex[1].$bgHex[2].$bgHex[2];
            }
            $ffBgColor = '0x' . strtoupper($bgHex);

            $tickerBarH = $tickerFontSize + ($tickerBorderW * 2);
            $tickerY = match ($tickerPos) {
                'top'    => "{$tickerMargin}+({$tickerBarH}-th)/2",
                'center' => '(h-th)/2',
                default  => "h-{$tickerBarH}-{$tickerMargin}+({$tickerBarH}-th)/2",
            };
            $tickerBoxY = match ($tickerPos) {
                'top'    => (string) $tickerMargin,
                'center' => "(ih-{$tickerBarH})/2",
                default  => "ih-{$tickerBarH}-{$tickerMargin}",
            };

            $padY = max(0, (int) round(($tickerBarH - $tickerFontSize) / 2));

            // Full-width background bar using drawbox
            $filterParts[] = "[{$lastLabel}]drawbox=x=0:y={$tickerBoxY}:w=iw:h={$tickerBarH}:color={$ffBgColor}@{$tickerBgOpacityFp}:t=fill[ticker_bar]";
            $lastLabel = 'ticker_bar';

            // Single scrolling drawtext using the combined ticker.txt file
            // All items are concatenated with separators in writeTickerFile()
            $tickerFile = $this->tickerFilePath($channel);
            $escapedTickerFile = str_replace("'", "'\\''", $tickerFile);
            $filterParts[] = "[{$lastLabel}]drawtext=textfile='{$escapedTickerFile}':reload=1:y={$tickerY}:x=w-mod(max(t*{$tickerSpeed}\\,0)\\,w+tw):fontcolor={$tickerFontColor}:fontsize={$tickerFontSize}[with_ticker]";
            $lastLabel = 'with_ticker';

            // Optional label prefix (e.g. "BREAKING NEWS") rendered as a separate static drawtext
            $tickerLabel = trim((string) ($channel->ticker_label ?? ''));
            if ($tickerLabel !== '') {
                $labelColor = $channel->ticker_label_color ?? '#ff0000';
                $labelBgHex = ltrim($channel->ticker_label_bg ?? '#ffffff', '#');
                if (strlen($labelBgHex) === 3) {
                    $labelBgHex = $labelBgHex[0].$labelBgHex[0].$labelBgHex[1].$labelBgHex[1].$labelBgHex[2].$labelBgHex[2];
                }
                $ffLabelBg = '0x' . strtoupper($labelBgHex);
                $labelFontSize = $this->px(max(10, min(72, (int) ($channel->ticker_font_size ?? 24))), $s);
                $escapedLabel = str_replace(['\\', "'", ':', '[', ']'], ['\\\\', "'\\''", '\\:', '\\[', '\\]'], $tickerLabel);
                $filterParts[] = "[{$lastLabel}]drawtext=text='{$escapedLabel}':y=h-{$tickerBarH}-{$tickerBarH}-{$tickerMargin}-4:x={$tickerMargin}:fontcolor={$labelColor}:fontsize={$labelFontSize}:box=1:boxcolor={$ffLabelBg}@1.0:boxborderw={$tickerBorderW}[with_label]";
                $lastLabel = 'with_label';
            }
        }

        // Clock overlay
        $clockEnabled = $channel->clock_enabled ?? true;
        if ($clockEnabled) {
            $clockPos      = $channel->clock_position ?? 'top-left';
            $clockFontsize = $this->px(max(12, min(72, (int) ($channel->clock_fontsize ?? 28))), $s);
            $clockColor    = $channel->clock_color ?? 'white';
            $clockMargin   = $this->px(15, $s);
            $clockBorderW  = $this->px(6, $s);

            // Write clock time to a file every second (localtime=1 not supported in all FFmpeg builds)
            $clockFile = $this->startClockWriter($channel);
            $escapedClockFile = str_replace("'", "'\\''", $clockFile);

            $clockPosExpr = match ($clockPos) {
                'top-right'    => "x=w-tw-{$clockMargin}:y={$clockMargin}",
                'bottom-left'  => "x={$clockMargin}:y=h-th-{$clockMargin}",
                'bottom-right' => "x=w-tw-{$clockMargin}:y=h-th-{$clockMargin}",
                default        => "x={$clockMargin}:y={$clockMargin}",
            };

            // Free X/Y positioning takes priority over named preset
            $clockX = $channel->clock_x;
            $clockY = $channel->clock_y;
            if ($clockX !== null && $clockY !== null) {
                $scaledX = (int) round($clockX * $s);
                $scaledY = (int) round($clockY * $s);
                $cx = $scaledX < 0 ? "w-tw" . $scaledX : (string) $scaledX;
                $cy = $scaledY < 0 ? "h-th" . $scaledY : (string) $scaledY;
                $clockPosExpr = "x={$cx}:y={$cy}";
            }

            $filterParts[] = "[{$lastLabel}]drawtext=textfile='{$escapedClockFile}':reload=1:{$clockPosExpr}:fontcolor={$clockColor}:fontsize={$clockFontsize}:box=1:boxcolor=black@0.5:boxborderw={$clockBorderW}[clock_out]";
            $lastLabel = 'clock_out';
        }

        // NOW PLAYING overlay
        $lowerthirdEnabled = $channel->lowerthird_enabled ?? true;
        if ($lowerthirdEnabled) {
            $metaFile = $this->metaFilePath($channel);
            $escapedMetaFile = str_replace("'", "'\\''", $metaFile);

            $ltFontsize  = $this->px(max(10, min(72, (int) ($channel->lowerthird_fontsize ?? 20))), $s);
            $ltMargin    = $this->px(15, $s);
            $ltBorderW   = $this->px(4, $s);
            $ltFontColor = $channel->lowerthird_font_color ?? '#ffffff';
            $ltBgColor   = $channel->lowerthird_bg_color ?? '#334155';
            $ltBgOpacity = max(0, min(100, (int) ($channel->lowerthird_bg_opacity ?? 80)));
            $ltBgOpacityFp = number_format($ltBgOpacity / 100, 2, '.', '');

            $ltBgHex = ltrim($ltBgColor, '#');
            if (strlen($ltBgHex) === 3) {
                $ltBgHex = $ltBgHex[0].$ltBgHex[0].$ltBgHex[1].$ltBgHex[1].$ltBgHex[2].$ltBgHex[2];
            }
            $ffLtBgColor = '0x' . strtoupper($ltBgHex);

            // Free X/Y positioning takes priority over named preset
            $ltX = $channel->lowerthird_x;
            $ltY = $channel->lowerthird_y;
            if ($ltX !== null && $ltY !== null) {
                $scaledX = (int) round($ltX * $s);
                $scaledY = (int) round($ltY * $s);
                $ox = $scaledX < 0 ? "W-tw" . $scaledX : (string) $scaledX;
                $oy = $scaledY < 0 ? "H-th" . $scaledY : (string) $scaledY;
                $ltPosExpr = "x={$ox}:y={$oy}";
            } else {
                $ltPos = $channel->lowerthird_position ?? 'bottom-left';
                $ltPosExpr = match ($ltPos) {
                    'top-left'     => "x={$ltMargin}:y={$ltMargin}",
                    'top-right'    => "x=w-tw-{$ltMargin}:y={$ltMargin}",
                    'bottom-right' => "x=w-tw-{$ltMargin}:y=h-th-{$ltMargin}",
                    default        => "x={$ltMargin}:y=h-th-{$ltMargin}",
                };
            }

            $filterParts[] = "[{$lastLabel}]drawtext=textfile='{$escapedMetaFile}':reload=1:{$ltPosExpr}:fontcolor={$ltFontColor}:fontsize={$ltFontsize}:box=1:boxcolor={$ffLtBgColor}@{$ltBgOpacityFp}:boxborderw={$ltBorderW}[final_video]";
            $lastLabel = 'final_video';
        }

        // Video encoding
        $fps = max(1, (int) ($channel->push_framerate ?? 25));
        $bitrate = (int) ($channel->push_video_bitrate ?? 3000);

        $videoEncode = [
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-tune', 'zerolatency',
            '-b:v', "{$bitrate}k",
            // Roomier rate control: 1.6× maxrate + 4× buffer lets the encoder
            // ride out bitrate spikes (action scenes, VBR URL sources) without
            // starving frames and causing visible stutter.
            '-maxrate', (int) round($bitrate * 1.6) . 'k',
            '-bufsize', (int) ($bitrate * 4) . 'k',
            '-pix_fmt', 'yuv420p',
            '-g', (string) ($fps * 2),
            '-keyint_min', (string) ($fps * 2),
            '-sc_threshold', '0',
            '-force_key_frames', 'expr:gte(t,n_forced*2)',
            '-bf', '0',
            // Auto-threading: the overlay filter chain (logo + ticker + clock +
            // lower-third) is expensive; pinning to 2 threads starves it on the VPS.
            '-threads', '0',
        ];

        // Audio encoding
        $audioEncode = [
            '-c:a', 'aac',
            '-b:a', ((int) ($channel->push_audio_bitrate ?? 128)) . 'k',
            '-ar', (string) (int) ($channel->push_audio_samplerate ?? 48000),
            '-ac', (string) (int) ($channel->push_audio_channels ?? 2),
        ];

        // Assemble filter_complex
        $filterComplex = implode(';', $filterParts);

        $cmd = array_merge($cmd, [
            '-filter_complex', $filterComplex,
            '-map', "[{$lastLabel}]",
            '-map', '0:a?',
        ], $videoEncode, $audioEncode, [
            '-f', 'hls',
            '-hls_time', (string) $segDur,
            '-hls_list_size', '3',
            '-hls_flags', 'delete_segments+omit_endlist+append_list',
            '-hls_delete_threshold', '1',
            '-hls_segment_type', 'mpegts',
            '-hls_segment_filename', $segPattern,
            '-hls_allow_cache', '0',
            '-hls_start_number_source', 'epoch',
            '-max_muxing_queue_size', '4096',
            $m3u8Out,
        ]);

        return $cmd;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CLOCK WRITER
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Start a background shell loop that writes the current time (formatted
     * per the channel's clock_format and timezone) to a file every second.
     * FFmpeg reads this file with textfile=+reload=1 instead of localtime=1,
     * which is not supported in all FFmpeg builds.
     * Returns the path to the clock text file.
     */
    private function startClockWriter(Channel $channel): string
    {
        $clockFile = $this->clockFilePath($channel);
        $pidFile   = $this->clockWriterPidFile($channel);

        // Kill any existing writer for this channel — including stale processes
        // from previous restarts that may still be writing to the same clock file
        $oldPid = (int) @file_get_contents($pidFile);
        if ($oldPid > 0) {
            exec("kill {$oldPid} 2>/dev/null");
        }
        // Kill any other orphaned writers targeting this clock file (race condition guard)
        $escapedClockFileForGrep = escapeshellarg($clockFile);
        exec("pgrep -f " . escapeshellarg("date.*" . basename($clockFile)) . " 2>/dev/null", $orphans);
        foreach ($orphans as $orphanPid) {
            $orphanPid = (int) trim($orphanPid);
            if ($orphanPid > 0 && $orphanPid !== $oldPid) {
                exec("kill {$orphanPid} 2>/dev/null");
            }
        }

        $timezone  = $channel->timezone ?? config('app.timezone', 'UTC');
        // Convert strftime-style format (e.g. %H:%M:%S) to date() format
        $rawFormat = $channel->clock_format ?? '%H:%M:%S';
        // Unescape any FFmpeg-escaped colons first, then use as strftime
        $strftimeFmt = str_replace('\:', ':', $rawFormat);
        $escapedFmt  = escapeshellarg($strftimeFmt);
        $escapedFile = escapeshellarg($clockFile);
        $escapedTz   = escapeshellarg($timezone);

        $shell = "setsid sh -c "
            . escapeshellarg(
                "while true; do TZ={$escapedTz} date +{$escapedFmt} > {$escapedFile} 2>/dev/null; sleep 1; done"
            )
            . ' </dev/null >/dev/null 2>&1 & echo $!';

        $pid = (int) trim((string) shell_exec($shell));
        if ($pid > 0) {
            // Ensure the pids directory and file are writable by the web process
            $pidsDir = dirname($pidFile);
            if (! is_dir($pidsDir)) {
                mkdir($pidsDir, 0775, true);
            }
            // If an existing root-owned pid file blocks us, remove it first
            if (file_exists($pidFile) && ! is_writable($pidFile)) {
                @unlink($pidFile);
            }
            @file_put_contents($pidFile, $pid);
        }

        // Seed the file immediately so FFmpeg doesn't start with an empty textfile
        $tz = new \DateTimeZone($timezone);
        $now = new \DateTime('now', $tz);
        // Simple strftime-style substitution for the seed write
        $seed = strtr($strftimeFmt, [
            '%H' => $now->format('H'),
            '%M' => $now->format('i'),
            '%S' => $now->format('s'),
            '%I' => $now->format('h'),
            '%p' => $now->format('A'),
            '%d' => $now->format('d'),
            '%m' => $now->format('m'),
            '%Y' => $now->format('Y'),
            '%y' => $now->format('y'),
        ]);
        file_put_contents($clockFile, $seed);

        return $clockFile;
    }

    /**
     * Stop the clock writer background process for a channel.
     */
    private function stopClockWriter(Channel $channel): void
    {
        $pidFile = $this->clockWriterPidFile($channel);
        $pid = (int) @file_get_contents($pidFile);
        if ($pid > 0) {
            exec("kill {$pid} 2>/dev/null");
        }
        @unlink($pidFile);
        // Kill any orphaned writers targeting this channel's clock file
        $clockFile = $this->clockFilePath($channel);
        exec("pgrep -f " . escapeshellarg("date.*" . basename($clockFile)) . " 2>/dev/null", $orphans);
        foreach ($orphans as $orphanPid) {
            $orphanPid = (int) trim($orphanPid);
            if ($orphanPid > 0) exec("kill {$orphanPid} 2>/dev/null");
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  NOW PLAYING WRITER
    //  Keeps the on-screen title EXACTLY synced to actual ffmpeg playback
    //  by reading the -progress output file every second.
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Read the last out_time_us value from ffmpeg's -progress file.
     * Returns seconds (float) or null when no progress has been written yet.
     */
    public function readPlayoutOffset(string $progressFile): ?float
    {
        if (! file_exists($progressFile)) {
            return null;
        }
        // The file is small (rewritten each progress tick); tail it for speed.
        $content = (string) @file_get_contents($progressFile);
        $lines = explode("\n", $content);
        $us = 0;
        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'out_time_us=')) {
                $us = (int) substr($line, strlen('out_time_us='));
                break;
            }
        }
        return $us > 0 ? ($us / 1_000_000.0) : null;
    }

    /**
     * Launch a background writer (`php artisan tv:now-playing-writer {id}`)
     * that polls the ffmpeg -progress file each second and rewrites the CG
     * meta file so the overlay always matches the actual on-air picture.
     */
    private function startNowPlayingWriter(Channel $channel): void
    {
        $pidFile = $this->nowPlayingWriterPidFile($channel);

        // Kill any existing writer for this channel — including stale processes
        // from previous restarts.
        $oldPid = (int) @file_get_contents($pidFile);
        if ($oldPid > 0) {
            exec("kill {$oldPid} 2>/dev/null");
        }
        exec("pgrep -f " . escapeshellarg("tv:now-playing-writer {$channel->id}") . " 2>/dev/null", $orphans);
        foreach ($orphans as $orphanPid) {
            $orphanPid = (int) trim($orphanPid);
            if ($orphanPid > 0 && $orphanPid !== $oldPid) {
                exec("kill {$orphanPid} 2>/dev/null");
            }
        }

        $artisan = base_path('artisan');
        $phpExe  = trim((string) shell_exec('command -v php 2>/dev/null')) ?: 'php';
        $shell = "setsid sh -c "
            . escapeshellarg($phpExe . ' ' . escapeshellarg($artisan) . ' tv:now-playing-writer ' . $channel->id)
            . ' </dev/null >/dev/null 2>&1 & echo $!';

        $pid = (int) trim((string) shell_exec($shell));
        if ($pid > 0) {
            $pidsDir = dirname($pidFile);
            if (! is_dir($pidsDir)) {
                mkdir($pidsDir, 0775, true);
            }
            if (file_exists($pidFile) && ! is_writable($pidFile)) {
                @unlink($pidFile);
            }
            @file_put_contents($pidFile, $pid);
            Log::info("[TvPlayout] {$channel->name} now-playing writer started — PID {$pid}");
        }
    }

    /**
     * Stop the background now-playing writer for a channel.
     */
    private function stopNowPlayingWriter(Channel $channel): void
    {
        $pidFile = $this->nowPlayingWriterPidFile($channel);
        $pid = (int) @file_get_contents($pidFile);
        if ($pid > 0) {
            exec("kill {$pid} 2>/dev/null");
        }
        @unlink($pidFile);
        exec("pgrep -f " . escapeshellarg("tv:now-playing-writer {$channel->id}") . " 2>/dev/null", $orphans);
        foreach ($orphans as $orphanPid) {
            $orphanPid = (int) trim($orphanPid);
            if ($orphanPid > 0) exec("kill {$orphanPid} 2>/dev/null");
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CG FILE MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Write the ticker text file from ticker_items JSON array or fallback to ticker_text.
     * When per-item files exist, each item is written to its own file for per-item styling.
     */
    public function writeTickerFile(Channel $channel): void
    {
        $items = $channel->ticker_items ?? [];

        if (! empty($items)) {
            $parts = array_map(fn ($i) => trim((string) ($i['text'] ?? '')), $items);
            $parts = array_filter($parts);
            $text = implode('   •   ', $parts);
        } else {
            $text = trim((string) $channel->ticker_text);
        }
        $text = str_replace('%', '％', $text ?: ' ');
        file_put_contents($this->tickerFilePath($channel), $text);
    }

    /**
     * Remove per-item ticker files (used when blanking the ticker for clean items).
     */
    public function clearTickerItemFiles(Channel $channel): void
    {
        $cgDir = $this->cgDirectory($channel);
        foreach (glob("{$cgDir}/ticker_*.txt") as $f) {
            file_put_contents($f, ' ');
        }
    }

    /**
     * Write the current playing metadata file for on-screen overlay.
     * When the current item's media_group is 'clean', blanks all CG text files
     * so overlays show nothing without requiring an FFmpeg restart.
     *
     * @param ?float $playbackOffset Seconds into the looping playlist as reported
     *        by ffmpeg's -progress output (ACTUAL playback). When null, falls back
     *        to wall-clock scheduling via last_live_at. Always using the actual
     *        offset keeps the overlay title perfectly locked to the picture even
     *        when ffmpeg buffered at start or dropped frames under load.
     */
    public function writeMetaFile(Channel $channel, ?float $playbackOffset = null): void
    {
        $channel = $channel->fresh();

        $items = PlaylistItem::where('channel_id', $channel->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $item = null;

        if ($items->isNotEmpty()) {
            $totalDuration = (float) $items->sum('duration');

            $offset = null;
            if ($playbackOffset !== null && $totalDuration > 0) {
                $offset = fmod((float) $playbackOffset, $totalDuration);
            } elseif ($totalDuration > 0 && $channel->last_live_at) {
                $elapsed = (float) $channel->last_live_at->diffInSeconds(now(), true);
                $offset  = fmod($elapsed, $totalDuration);
            }

            if ($offset !== null) {
                $cursor = 0.0;
                foreach ($items as $candidate) {
                    $dur = (float) $candidate->duration;
                    if ($dur <= 0) continue;
                    if ($offset < $cursor + $dur) {
                        $item = $candidate;
                        break;
                    }
                    $cursor += $dur;
                }
            }

            if (! $item) {
                $item = $items->first();
            }
        }

        $isClean = $item && ! $item->hasOverlays();

        file_put_contents($this->metaFilePath($channel), $isClean ? ' ' : ($item ? 'NOW PLAYING: ' . $item->display_title : 'NO PLAYLIST ITEMS'));

        if ($isClean) {
            file_put_contents($this->tickerFilePath($channel), ' ');
            $this->clearTickerItemFiles($channel);
        } else {
            $this->writeTickerFile($channel);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  PATH HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private function cgDirectory(Channel $channel): string
    {
        return $channel->dvr_directory . '/cg';
    }

    private function logoActivePath(Channel $channel): string
    {
        return $this->cgDirectory($channel) . '/logo_active.png';
    }

    private function logoBlankPath(Channel $channel): string
    {
        return $this->cgDirectory($channel) . '/logo_blank.png';
    }

    /**
     * Create a 1×1 transparent PNG used when logo is disabled or missing.
     */
    private function ensureLogoBlank(Channel $channel): void
    {
        $blank = $this->logoBlankPath($channel);
        if (file_exists($blank)) {
            return;
        }
        // Minimal 1×1 transparent PNG (67 bytes)
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
        file_put_contents($blank, $png);
    }

    /**
     * Point logo_active.png symlink to the real logo or blank PNG.
     * Called on start, logo upload, logo remove, and toggle.
     */
    public function updateLogoSymlink(Channel $channel): void
    {
        $this->ensureLogoBlank($channel);
        $active = $this->logoActivePath($channel);

        $channel->loadMissing('logoMedia');
        $logo = $channel->logoMedia;
        $enabled = $channel->logo_enabled ?? true;
        $target = ($enabled && $logo && file_exists($logo->filepath))
            ? $logo->filepath
            : $this->logoBlankPath($channel);

        // Atomic symlink swap
        $tmp = $active . '.tmp';
        @unlink($tmp);
        symlink($target, $tmp);
        rename($tmp, $active);
    }

    private function tickerFilePath(Channel $channel): string
    {
        return $this->cgDirectory($channel) . '/ticker.txt';
    }

    private function metaFilePath(Channel $channel): string
    {
        return $this->cgDirectory($channel) . '/current_playing.txt';
    }

    private function concatFilePath(Channel $channel): string
    {
        return $channel->dvr_directory . '/tv_playlist.txt';
    }

    private function clockFilePath(Channel $channel): string
    {
        return $this->cgDirectory($channel) . '/clock.txt';
    }

    private function clockWriterPidFile(Channel $channel): string
    {
        return storage_path('app/pids/clock_writer_' . $channel->id . '.pid');
    }

    private function nowPlayingProgressFile(Channel $channel): string
    {
        return $channel->dvr_directory . '/tv_progress.txt';
    }

    private function nowPlayingWriterPidFile(Channel $channel): string
    {
        return storage_path('app/pids/nowplaying_writer_' . $channel->id . '.pid');
    }

    private function formatDuration(float $seconds): string
    {
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds / 60) % 60);
        $secs = (int) floor($seconds % 60);
        $ms = (int) round(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $ms);
    }
}
