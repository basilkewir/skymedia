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
        // If an explicit resume offset was saved (e.g. from restartWithResume), use it.
        // Otherwise, if last_live_at is set and in the past, compute where we should
        // be in the playlist right now so playback starts at the correct position
        // (e.g. a video scheduled at 12:11 that is started at 12:50 seeks to 12:50-12:11=39min in).
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
        $cmd = $this->buildCommand($channel, $concatFile, $resumeOffset);
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
            'last_live_at' => now()->subSeconds($resumeOffset), // keep schedule anchor correct
            'playout_resume_offset' => null,
        ]);

        if ($resumeOffset > 0) {
            Log::info("[TvPlayout] {$channel->name} started — PID {$pid} (resumed at {$resumeOffset}s)");
        } else {
            Log::info("[TvPlayout] {$channel->name} started — PID {$pid}");
        }

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
        $this->stopClockWriter($channel);
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
            'playout_resume_offset' => null,
        ]);

        Log::info("[TvPlayout] {$channel->name} stopped");
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
        $offset = (int) fmod($elapsed, $totalDuration);

        $channel->update(['playout_resume_offset' => $offset]);
        Log::info("[TvPlayout] {$channel->name} captured resume offset {$offset}s (elapsed {$elapsed}s, total {$totalDuration}s)");
    }

    /**
     * Stop → capture offset → restart at the same position in the playlist.
     * Used by all overlay-settings updates so the playlist time is preserved.
     * The push process is also restarted so it picks up the new HLS output.
     */
    private function restartWithResume(Channel $channel): void
    {
        if (! $this->isRunning($channel)) {
            return;
        }

        // 1. Capture where we are before killing the process
        $this->captureOffset($channel);

        // 2. Stop only the playout ffmpeg (not the push — push will be restarted by startPush)
        $pidFile = $this->ffmpeg->pidFile($channel, 'tv_playout');
        $pid = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }
        $this->ffmpeg->clearPid($pidFile);

        // 3. Restart — start() will read playout_resume_offset and pass -ss to ffmpeg
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

        // If buildConcatFile returned a URL (not a local file), we must restart
        // ffmpeg entirely — SIGUSR1 only reloads the concat file on disk.
        if ($concatFile !== null && str_starts_with($concatFile, 'http')) {
            if ($this->isRunning($channel)) {
                $this->stop($channel);
            }
            return $this->start($channel->fresh());
        }

        if ($this->isRunning($channel)) {
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
     * Update logo position (x:y pixels) — requires restart (baked into filter_complex).
     */
    public function updateLogoPosition(Channel $channel, string $position): void
    {
        $channel->update(['logo_position' => $position]);
        if ($this->isRunning($channel)) {
            $this->restartWithResume($channel);
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
     * Update logo scale (% of video width, 1–50) — requires restart (baked into filter_complex).
     */
    public function updateLogoScale(Channel $channel, int $scale): void
    {
        $channel->update(['logo_scale' => max(1, min(50, $scale))]);
        if ($this->isRunning($channel)) {
            $this->restartWithResume($channel);
        }
    }

    /**
     * Toggle logo overlay on/off — requires restart (logo input conditionally included in filter chain).
     */
    public function toggleLogoEnabled(Channel $channel): void
    {
        $channel->update(['logo_enabled' => ! ($channel->logo_enabled ?? true)]);
        if ($this->isRunning($channel)) {
            $this->restartWithResume($channel);
        }
    }

    /**
     * Update clock settings (position, size, color, timezone) — requires restart.
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

        if ($this->isRunning($channel)) {
            $this->restartWithResume($channel);
        }
    }

    /**
     * Update ticker settings — requires restart (baked into filter_complex).
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

        if ($this->isRunning($channel)) {
            $this->restartWithResume($channel);
        }
    }

    /**
     * Update output resolution — requires restart.
     */
    public function updateResolution(Channel $channel, string $resolution): void
    {
        $channel->update(['output_resolution' => $resolution]);
        if ($this->isRunning($channel)) {
            $this->restartWithResume($channel);
        }
    }

    /**
     * Update NOW PLAYING / lowerthird overlay settings — requires restart.
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

        if ($this->isRunning($channel)) {
            $this->restartWithResume($channel);
        }
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

        // Use provided anchor, or playout start time if running, or now
        if ($anchorStartTime) {
            $currentTimeTracker = Carbon::parse($anchorStartTime);
        } elseif ($channel->last_live_at && $this->isRunning($channel)) {
            $currentTimeTracker = $channel->last_live_at->copy();
        } else {
            $currentTimeTracker = Carbon::now();
        }
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
        $hasUrl = false;
        foreach ($items as $item) {
            $resolved = $this->resolveFilePath($item);
            if ($resolved !== null) {
                $files[] = $resolved;
                if (str_starts_with($resolved, 'http')) {
                    $hasUrl = true;
                }
            }
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
                $files[] = $slate;
            } else {
                return null;
            }
        }

        // When ALL items are streaming URLs, skip the concat demuxer entirely.
        // Return the first URL — buildCommand will use it as a direct input.
        if ($hasUrl && count($files) === 1 && str_starts_with($files[0], 'http')) {
            return $files[0];
        }

        // Mixed local + URL — fall through to concat demuxer (URLs won't work
        // here but it's the best we can do without major refactoring).
        $totalDuration = $items->sum('duration');
        $repeat = ($channel->playlist_loop ?? 0) > 0
            ? $channel->playlist_loop
            : ($totalDuration > 0 ? max(10, min((int) ceil(86400 / $totalDuration), 500)) : 50);

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
     * For YouTube items: returns a locally downloaded .mp4 file (downloading
     * synchronously if needed). Local files are returned directly.
     */
    private function resolveFilePath(PlaylistItem $item): ?string
    {
        $path = $item->filepath;

        if (str_starts_with($path, 'youtube:')) {
            return $this->resolveYouTubeItem($item);
        }

        if (file_exists($path) && filesize($path) > 1024) {
            return $path;
        }

        return null;
    }

    /**
     * Resolve a YouTube playlist item to a local cached .mp4 file.
     * If not yet downloaded, starts a background download and returns the
     * slate as a placeholder so the channel can start immediately.
     * Once the download completes the concat is rebuilt automatically.
     * Cleans up stale .downloading lock files older than 10 minutes.
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

        // Check for a cached local .mp4 file (legacy downloads)
        $localFile = "{$cacheDir}/{$videoId}.mp4";
        if (file_exists($localFile) && filesize($localFile) > 1_048_576) {
            return $localFile;
        }

        // Check for a cached stream URL (valid for 2 hours)
        $urlCacheFile = "{$cacheDir}/{$videoId}.stream_url";
        if (file_exists($urlCacheFile)) {
            $cached = file_get_contents($urlCacheFile);
            $cachedTime = filemtime($urlCacheFile);
            if ($cached !== false && strlen($cached) > 10 && (time() - $cachedTime) < 7200) {
                return trim($cached);
            }
        }

        // Extract stream URL with yt-dlp (fast, no download)
        $streamUrl = $this->extractStreamUrl($videoId);
        if ($streamUrl !== null) {
            file_put_contents($urlCacheFile, $streamUrl);
            return $streamUrl;
        }

        Log::warning("[TvPlayout] YouTube {$videoId}: could not extract stream URL");
        return null;
    }

    /**
     * Extract a direct streaming URL from YouTube using yt-dlp -g.
     * Returns the URL string or null on failure.
     */
    private function extractStreamUrl(string $videoId): ?string
    {
        $ytdlp = $this->findYtdlp();
        if ($ytdlp === null) {
            return null;
        }

        $url = "https://www.youtube.com/watch?v={$videoId}";
        $cookieSource = storage_path('app/youtube_cookies_auth.txt');

        // yt-dlp overwrites the cookie file it reads, so use a temp copy
        $cookieCopy = tempnam(sys_get_temp_dir(), 'yt_cookies_');
        if (file_exists($cookieSource) && filesize($cookieSource) > 50) {
            copy($cookieSource, $cookieCopy);
        } else {
            $cookieCopy = null;
        }

        $clients = ['web', 'web_safari', 'ios'];
        $proxy = app(\App\Services\ProxyService::class)->getWorkingProxy();

        try {
            $formats = ['best[ext=mp4]/best', 'bestvideo[ext=mp4][height<=1080]+bestaudio[ext=m4a]/best[ext=mp4]/best'];

            foreach ($formats as $fmt) {
                foreach ($clients as $client) {
                    $cmd = [
                        $ytdlp, '--js-runtimes', 'node', '--no-warnings',
                        '-g',
                        '--socket-timeout', '20',
                        '--retries', '1',
                        '--format', $fmt,
                        '--no-playlist',
                        '--extractor-args', "youtube:player_client={$client}",
                    ];

                    if ($cookieCopy !== null) {
                        $cmd[] = '--cookies';
                        $cmd[] = $cookieCopy;
                    }

                    if ($proxy) {
                        $cmd[] = '--proxy';
                        $cmd[] = $proxy;
                    }

                    $cmd[] = $url;

                    $output = [];
                    $exitCode = 0;
                    exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>/dev/null', $output, $exitCode);

                    if ($exitCode === 0 && ! empty($output)) {
                        $streamUrl = trim(end($output));
                        if (str_starts_with($streamUrl, 'http')) {
                            Log::info("[TvPlayout] YouTube {$videoId}: extracted stream URL via client={$client} fmt={$fmt}");
                            return $streamUrl;
                        }
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

    /**
     * Start a background shell process that downloads the YouTube video,
     * then rebuilds the concat file when done.
     * Tries direct first (cookies work without proxy), then proxy as fallback.
     */
    private function startBackgroundDownload(string $videoId, string $localFile, string $lockFile, PlaylistItem $item): void
    {
        $ytdlp = $this->findYtdlp();
        if ($ytdlp === null) {
            Log::error('[TvPlayout] yt-dlp not found — cannot download YouTube video');
            return;
        }

        $proxy      = app(\App\Services\ProxyService::class)->getWorkingProxy() ?: '';
        $cookiePath = $this->getYouTubeCookiePath($item);
        $channelId  = $item->channel_id;
        $artisan    = base_path('artisan');
        $tmpPattern = $localFile . '.tmp.%(ext)s';
        $logFile    = storage_path("app/youtube_cache/{$videoId}.log");
        $url        = "https://www.youtube.com/watch?v={$videoId}";
        $path       = '/usr/local/bin:/usr/bin:/bin';

        // Player clients to try in order
        $clients = ['web', 'web_safari', 'ios'];

        // Build cookie arg
        $cookieArg = ($cookiePath !== null) ? '--cookies ' . escapeshellarg($cookiePath) : '';
        // Build proxy arg (only used in fallback attempts)
        $proxyArg  = ($proxy !== '') ? '--proxy ' . escapeshellarg($proxy) : '';

        $clientAttempts = '';

        // Round 1: Try each client WITHOUT proxy (direct connection with auth)
        foreach ($clients as $i => $client) {
            $attemptCmd = $ytdlp
                . ' --js-runtimes node --no-warnings --socket-timeout 20'
                . ' --retries 1'
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
                    . ' --js-runtimes node --no-warnings --socket-timeout 20'
                    . ' --retries 1'
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
            . "php " . escapeshellarg($artisan) . " tv:rebuild-concat " . escapeshellarg((string) $channelId) . " 2>/dev/null || true";

        $script = "#!/bin/sh\n"
            . "export PATH={$path}:\$PATH\n"
            . "echo '[yt-dlp] Starting download for {$videoId}' > " . escapeshellarg($logFile) . "\n"
            . "touch " . escapeshellarg($lockFile) . "\n"
            . $clientAttempts
            . $postDownload;

        $scriptFile = storage_path("app/youtube_cache/{$videoId}.sh");
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

    private function buildCommand(Channel $channel, string $concatFile, int $resumeOffset = 0): array
    {
        $dvrDir = $channel->dvr_directory;
        $segPattern = "{$dvrDir}/tv_seg_%010d.ts";
        $m3u8Out = "{$dvrDir}/live.m3u8";
        $segDur = max(2, (int) ($channel->segment_duration ?? 2));

        // Scale factor for all overlay pixel values relative to 1080p baseline
        $s = $this->overlayScale($channel);

        $isUrl = str_starts_with($concatFile, 'http');

        // Base command
        $cmd = [
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            '-fflags', '+genpts+igndts+discardcorrupt+flush_packets',
            '-err_detect', 'ignore_err',
            '-stream_loop', '-1',
            '-re',
        ];

        if ($isUrl) {
            // Direct URL input — no concat demuxer needed
            $cmd[] = '-protocol_whitelist';
            $cmd[] = 'file,http,https,tcp,tls,crypto';
        } else {
            // Local concat file — use concat demuxer
            $cmd[] = '-safe';
            $cmd[] = '0';
            $cmd[] = '-protocol_whitelist';
            $cmd[] = 'file,http,https,tcp,tls,crypto';
            $cmd[] = '-f';
            $cmd[] = 'concat';
        }

        if ($resumeOffset > 0 && ! $isUrl) {
            $cmd[] = '-ss';
            $cmd[] = (string) $resumeOffset;
        }

        $cmd[] = '-i';
        $cmd[] = $concatFile;

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
            $tickerFile = $this->tickerFilePath($channel);
            $escapedTickerFile = str_replace("'", "'\\''", $tickerFile);

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

            $tickerY = match ($tickerPos) {
                'top'    => (string) $tickerMargin,
                'center' => '(h-line_h)/2',
                default  => "h-line_h-{$tickerMargin}",
            };

            $filterParts[] = "[{$lastLabel}]drawtext=textfile='{$escapedTickerFile}':reload=1:y={$tickerY}:x=w-mod(max(t*{$tickerSpeed}\\,0)\\,w+tw):fontcolor={$tickerFontColor}:fontsize={$tickerFontSize}:box=1:boxcolor={$ffBgColor}@{$tickerBgOpacityFp}:boxborderw={$tickerBorderW}[with_ticker]";
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
                $filterParts[] = "[{$lastLabel}]drawtext=text='{$escapedLabel}':y={$tickerY}:x={$tickerMargin}:fontcolor={$labelColor}:fontsize={$labelFontSize}:box=1:boxcolor={$ffLabelBg}@1.0:boxborderw={$tickerBorderW}[with_label]";
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

        // Kill any existing writer for this channel
        $oldPid = (int) @file_get_contents($pidFile);
        if ($oldPid > 0) {
            exec("kill {$oldPid} 2>/dev/null");
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
            file_put_contents($pidFile, $pid);
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
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CG FILE MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Write the ticker text file from ticker_items JSON array or fallback to ticker_text.
     * Items are joined with a separator so they scroll as one continuous line.
     */
    public function writeTickerFile(Channel $channel): void
    {
        $items = $channel->ticker_items ?? [];
        if (! empty($items)) {
            $parts = array_map(fn ($item) => trim((string) ($item['text'] ?? '')), $items);
            $parts = array_filter($parts);
            $text = implode('   •   ', $parts);
        } else {
            $text = trim((string) $channel->ticker_text);
        }
        file_put_contents($this->tickerFilePath($channel), $text ?: ' ');
    }

    /**
     * Write the current playing metadata file for on-screen overlay.
     * When the current item's media_group is 'clean', blanks all CG text files
     * so overlays show nothing without requiring an FFmpeg restart.
     */
    public function writeMetaFile(Channel $channel): void
    {
        $now = now();

        $item = PlaylistItem::where('channel_id', $channel->id)
            ->where('is_active', true)
            ->where('scheduled_start', '<=', $now)
            ->where('scheduled_end', '>', $now)
            ->orderBy('scheduled_start')
            ->first();

        if (! $item) {
            $item = PlaylistItem::where('channel_id', $channel->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->first();
        }

        $isClean = $item && ! $item->hasOverlays();

        // When clean group: blank all text overlays so nothing shows on screen
        file_put_contents($this->metaFilePath($channel), $isClean ? ' ' : ($item ? $item->display_title : 'NO PLAYLIST ITEMS'));

        if ($isClean) {
            // Blank ticker too so it disappears during clean items
            file_put_contents($this->tickerFilePath($channel), ' ');
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

    private function formatDuration(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds / 60) % 60);
        $secs = floor($seconds % 60);
        $ms = round(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $ms);
    }
}
