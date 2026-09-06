<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use App\Models\PlaylistItem;
use App\Services\YouTubeMetadataService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TvPlayoutEngine — manages TV playout channels that run entirely on the VPS.
 *
 * FFmpeg reads a concat playlist file, applies CG overlays (logo, ticker, clock),
 * and outputs HLS segments that MediaMTX serves as the distribution edge.
 * When push_url is configured, a second ffmpeg process reads live.m3u8 and
 * pushes to the external RTMP/SRT server continuously.
 *
 * Architecture:
 *   playlist_items (DB) → concat.txt → FFmpeg (filter_complex) → HLS → MediaMTX
 *                                                                    └──→ Push ffmpeg → RTMP/SRT
 */
class TvPlayoutEngine
{
    public function __construct(
        protected FFmpegService $ffmpeg,
    ) {}

    /**
     * Start the TV playout engine for a channel.
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

        // Build and start the FFmpeg command
        $cmd = $this->buildCommand($channel, $concatFile);
        $pidFile = $this->ffmpeg->pidFile($channel, 'tv_playout');
        $logFile = $this->ffmpeg->logFile($channel, 'tv_playout');

        try {
            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile, 6);
        } catch (\Throwable $e) {
            Log::error("[TvPlayout] {$channel->name} failed to start: {$e->getMessage()}");
            $channel->update(['stream_status' => 'error', 'last_error' => substr($e->getMessage(), 0, 500)]);
            return false;
        }

        $channel->update([
            'is_active' => true,
            'stream_status' => 'live',
            'playout_status' => 'live',
            'playout_pid' => $pid,
            'source_live' => true,
            'last_live_at' => now(),
        ]);

        Log::info("[TvPlayout] {$channel->name} started — PID {$pid}");

        // Start external push if configured
        if (! empty($channel->push_url)) {
            $this->startPush($channel);
        }

        return true;
    }

    /**
     * Stop the TV playout engine.
     */
    public function stop(Channel $channel): void
    {
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
            'push_pid' => null,
            'push_status' => 'stopped',
            'source_live' => false,
        ]);

        Log::info("[TvPlayout] {$channel->name} stopped");
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
     * Start the external RTMP/SRT push process reading live.m3u8.
     * Called automatically by start() when push_url is set.
     * Safe to call again if already running (no-op).
     */
    public function startPush(Channel $channel): bool
    {
        if (empty($channel->push_url)) {
            return false;
        }

        if ($this->isPushRunning($channel)) {
            return true;
        }

        // live.m3u8 must exist before push can read it.
        // Wait up to 10s for the HLS engine to write the first segment.
        $m3u8 = $channel->dvr_directory . '/live.m3u8';
        $waited = 0;
        while (! file_exists($m3u8) && $waited < 10) {
            sleep(1);
            $waited++;
        }

        if (! file_exists($m3u8)) {
            Log::warning("[TvPlayout] {$channel->name}: live.m3u8 not ready, push deferred");
            return false;
        }

        $cmd = $this->ffmpeg->buildPushCommand($channel, $m3u8);
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $logFile = $this->ffmpeg->logFile($channel, 'push');

        try {
            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile, 6);
        } catch (\Throwable $e) {
            Log::error("[TvPlayout] {$channel->name} push failed to start: {$e->getMessage()}");
            $channel->update(['push_status' => 'error', 'last_error' => substr($e->getMessage(), 0, 500)]);
            return false;
        }

        $channel->update(['push_pid' => $pid, 'push_status' => 'live']);
        Log::info("[TvPlayout] {$channel->name} push started — PID {$pid} → {$channel->push_url}");

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
     * Rebuild the concat file seamlessly — send SIGUSR1 to the running ffmpeg
     * process so it reloads the concat demuxer without any output gap.
     * Falls back to a full restart only if the process is not running.
     */
    public function rebuild(Channel $channel): bool
    {
        $this->recalculateSchedule($channel);
        $this->writeMetaFile($channel);

        // Rewrite the concat file on disk first
        $concatFile = $this->buildConcatFile($channel);

        if ($this->isRunning($channel)) {
            // Signal ffmpeg to reload the concat list — zero gap on air
            $pidFile = $this->ffmpeg->pidFile($channel, 'tv_playout');
            $pid = $this->ffmpeg->readPid($pidFile);
            if ($pid > 0) {
                posix_kill($pid, SIGUSR1);
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
     * Update logo position (x:y pixels) — requires restart (baked into filter_complex).
     */
    public function updateLogoPosition(Channel $channel, string $position): void
    {
        $channel->update(['logo_position' => $position]);
        if ($this->isRunning($channel)) {
            $this->stop($channel);
            $this->start($channel->fresh());
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
     * Update logo scale (% of video width, 1–50) — requires restart (baked into filter_complex).
     */
    public function updateLogoScale(Channel $channel, int $scale): void
    {
        $channel->update(['logo_scale' => max(1, min(50, $scale))]);
        if ($this->isRunning($channel)) {
            $this->stop($channel);
            $this->start($channel->fresh());
        }
    }

    /**
     * Toggle logo overlay on/off — swaps symlink to blank PNG, no restart needed.
     */
    public function toggleLogoEnabled(Channel $channel): void
    {
        $channel->update(['logo_enabled' => ! ($channel->logo_enabled ?? true)]);
        $this->updateLogoSymlink($channel->fresh());
    }

    /**
     * Recalculate the precise schedule for all playlist items.
     */
    public function recalculateSchedule(Channel $channel, ?string $anchorStartTime = null): array
    {
        $items = PlaylistItem::where('channel_id', $channel->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $currentTimeTracker = $anchorStartTime ? Carbon::parse($anchorStartTime) : Carbon::now();
        $totalDuration = 0.0;

        foreach ($items as $item) {
            $start = clone $currentTimeTracker;

            $wholeSeconds = floor($item->duration);
            $microseconds = ($item->duration - $wholeSeconds) * 1_000_000;

            $end = clone $start;
            $end->addSeconds((int) $wholeSeconds)->addMicroseconds((int) $microseconds);

            $item->update([
                'scheduled_start' => $start,
                'scheduled_end' => $end,
            ]);

            $totalDuration += $item->duration;
            $currentTimeTracker = clone $end;
        }

        return [
            'total_duration_seconds' => $totalDuration,
            'formatted_total' => $this->formatDuration($totalDuration),
            'item_count' => $items->count(),
            'end_anchor' => $currentTimeTracker->toIso8601String(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CONCAT FILE BUILDER
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Build the FFmpeg concat playlist text file from database items.
     * Handles both local files and YouTube URLs (via cached stream URLs).
     * Repeats the playlist enough times for 24h of continuous playout.
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

        $files = [];
        foreach ($items as $item) {
            $resolved = $this->resolveFilePath($item);
            if ($resolved !== null) {
                $files[] = $resolved;
            }
        }

        if ($files === []) {
            // No items resolved yet (e.g. YouTube URLs pending prefetch).
            // Fall back to slate so the engine can start immediately.
            $slate = $channel->dvr_directory . '/slate.mp4';
            if (! file_exists($slate) || filesize($slate) < 1024) {
                try {
                    app(\App\Console\Commands\GenerateSlate::class)->generateSlate($channel);
                } catch (\Throwable) {}
            }
            if (file_exists($slate) && filesize($slate) > 1024) {
                $files[] = $slate;
            } else {
                return null;
            }
        }

        $totalDuration = $items->sum('duration');
        $repeat = $totalDuration > 0 ? max(10, min((int) ceil(86400 / $totalDuration), 500)) : 50;

        $concatPath = $this->concatFilePath($channel);
        $lines = [];
        for ($i = 0; $i < $repeat; $i++) {
            foreach ($files as $f) {
                $lines[] = "file '" . str_replace("'", "'\\''", $f) . "'";
            }
        }

        file_put_contents($concatPath, implode("\n", $lines));

        return $concatPath;
    }

    /**
     * Resolve a playlist item's filepath to a playable path/URL.
     * For local files: returns the path if it exists.
     * For YouTube items: returns the cached stream URL, or schedules a prefetch job.
     */
    private function resolveFilePath(PlaylistItem $item): ?string
    {
        $path = $item->filepath;

        // YouTube item — resolve from cache or trigger prefetch
        if (str_starts_with($path, 'youtube:')) {
            $cacheKey = "yt_stream_url_{$item->id}";
            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                return $cached;
            }

            // Not cached yet — dispatch prefetch job and skip this item for now
            $this->scheduleYouTubePrefetch($item);

            return null;
        }

        // Local file — check existence and minimum size
        if (file_exists($path) && filesize($path) > 1024) {
            return $path;
        }

        return null;
    }

    /**
     * Schedule a YouTube stream URL prefetch job if one isn't already pending.
     */
    private function scheduleYouTubePrefetch(PlaylistItem $item): void
    {
        $cacheKey = "yt_prefetch_scheduled_{$item->id}";

        // Only dispatch once per item per 10 minutes
        if (Cache::has($cacheKey)) {
            return;
        }

        \App\Jobs\PreFetchYouTubeStream::dispatch($item);
        Cache::put($cacheKey, true, now()->addMinutes(10));

        Log::info("[TvPlayout] Dispatched YouTube prefetch for item {$item->id}");
    }

    // ═══════════════════════════════════════════════════════════════════
    //  FFMPEG COMMAND BUILDER
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Build the FFmpeg command for TV playout with CG overlays.
     *
     * Architecture:
     *   Input 0: concat playlist (video + audio)
     *   Input 1: logo image (optional, -loop 1)
     *   Filter chain: [logo overlay] → [ticker drawtext] → [clock drawtext]
     *   Output: HLS segments → MediaMTX serves them
     */
    private function buildCommand(Channel $channel, string $concatFile): array
    {
        $dvrDir = $channel->dvr_directory;
        $segPattern = "{$dvrDir}/tv_seg_%010d.ts";
        $m3u8Out = "{$dvrDir}/live.m3u8";
        $segDur = max(2, (int) ($channel->segment_duration ?? 2));

        // Base command
        $cmd = [
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            '-fflags', '+genpts+igndts+discardcorrupt+flush_packets',
            '-err_detect', 'ignore_err',
            '-stream_loop', '-1',
            '-re',
            '-safe', '0',
            '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
            '-f', 'concat',
            '-i', $concatFile,
        ];

        // Logo overlay — always include via movie filter using fixed symlink path.
        // Symlink points to actual logo or blank PNG; swapping it updates overlay without restart.
        $this->ensureLogoBlank($channel);
        $logoActivePath = $this->logoActivePath($channel);
        $scalePct = max(1, min(50, (int) ($channel->logo_scale ?? 12)));

        // logo_position stored as "x:y" pixels; negative = from right/bottom edge.
        $position = $channel->logo_position ?? '20:20';
        if (preg_match('/^(-?\d+):(-?\d+)$/', $position, $m)) {
            $px = (int) $m[1];
            $py = (int) $m[2];
            $ox = $px < 0 ? "W-w{$px}" : (string) $px;
            $oy = $py < 0 ? "H-h{$py}" : (string) $py;
            $overlayPos = "{$ox}:{$oy}";
        } else {
            $overlayPos = match ($position) {
                'top-left'     => '20:20',
                'bottom-left'  => '20:H-h-20',
                'bottom-right' => 'W-w-20:H-h-20',
                default        => 'W-w-20:20',
            };
        }

        // Build filter_complex
        $filterParts = [];
        $lastLabel = '0:v';

        // movie filter re-reads the file on each loop — symlink swap takes effect immediately.
        $escapedLogoPath = str_replace("'", "'\\''", $logoActivePath);
        $filterParts[] = "movie='{$escapedLogoPath}':loop=0,scale=iw*{$scalePct}/100:-1[logo_scaled]";
        $filterParts[] = "[{$lastLabel}][logo_scaled]overlay={$overlayPos}[with_logo]";
        $lastLabel = 'with_logo';

        // Ticker (scrolling text)
        $tickerText = trim((string) $channel->ticker_text);
        if ($channel->ticker_enabled && $tickerText !== '') {
            $tickerFile = $this->tickerFilePath($channel);
            $escapedTickerFile = str_replace("'", "'\\''", $tickerFile);
            $filterParts[] = "[{$lastLabel}]drawtext=textfile='{$escapedTickerFile}':reload=1:y=h-line_h-10:x=w-mod(max(t*80\\,0)\\,w+tw):fontcolor=white:fontsize=24:box=1:boxcolor=black@0.65:boxborderw=8[with_ticker]";
            $lastLabel = 'with_ticker';
        }

        // Clock overlay
        $filterParts[] = "[{$lastLabel}]drawtext=text='%{localtime\:%H\:%M\:%S}':x=15:y=15:fontcolor=white:fontsize=28:box=1:boxcolor=black@0.5:boxborderw=6[final_video]";
        $lastLabel = 'final_video';

        // Video encoding
        $fps = max(1, (int) ($channel->push_framerate ?? 25));
        $bitrate = (int) ($channel->push_video_bitrate ?? 3000);

        $videoEncode = [
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-tune', 'zerolatency',
            '-b:v', "{$bitrate}k",
            '-maxrate', (int) ($bitrate * 1.2) . 'k',
            '-bufsize', (int) ($bitrate * 2) . 'k',
            '-pix_fmt', 'yuv420p',
            '-g', (string) ($fps * 2),
            '-keyint_min', (string) ($fps * 2),
            '-sc_threshold', '0',
            '-force_key_frames', 'expr:gte(t,n_forced*2)',
            '-bf', '0',
            '-threads', '2',
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
            '-hls_list_size', '60',
            '-hls_flags', 'delete_segments+omit_endlist+append_list',
            '-hls_delete_threshold', '100',
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
    //  CG FILE MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Write the ticker text file for FFmpeg drawtext to read.
     */
    public function writeTickerFile(Channel $channel): void
    {
        $text = trim((string) $channel->ticker_text);
        file_put_contents($this->tickerFilePath($channel), $text ?: ' ');
    }

    /**
     * Write the current playing metadata file.
     */
    public function writeMetaFile(Channel $channel): void
    {
        $item = PlaylistItem::where('channel_id', $channel->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->first();

        $meta = $item
            ? "NOW PLAYING: {$item->title}\nLENGTH: {$item->formatted_duration}"
            : 'NO PLAYLIST ITEMS';

        file_put_contents($this->metaFilePath($channel), $meta);
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
        if (is_link($tmp) || file_exists($tmp)) {
            unlink($tmp);
        }
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

    private function formatDuration(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds / 60) % 60);
        $secs = floor($seconds % 60);
        $ms = round(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $ms);
    }
}
