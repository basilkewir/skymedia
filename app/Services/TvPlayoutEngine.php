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
 * These channels have NO external ingest and NO push output.
 * FFmpeg reads a concat playlist file, applies CG overlays (logo, ticker, clock),
 * and outputs HLS segments that MediaMTX serves as the distribution edge.
 *
 * Architecture:
 *   playlist_items (DB) → concat.txt → FFmpeg (filter_complex) → HLS → MediaMTX
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

        return true;
    }

    /**
     * Stop the TV playout engine.
     */
    public function stop(Channel $channel): void
    {
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
     * Rebuild the concat file and restart FFmpeg (after playlist reorder/add/remove).
     */
    public function rebuild(Channel $channel): bool
    {
        $wasRunning = $this->isRunning($channel);
        if ($wasRunning) {
            $this->stop($channel);
        }

        // Recalculate schedule
        $this->recalculateSchedule($channel);

        // Write updated CG files
        $this->writeMetaFile($channel);

        if ($wasRunning || $channel->is_active) {
            return $this->start($channel->fresh());
        }

        return true;
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
     * Update logo and restart if needed.
     */
    public function updateLogo(Channel $channel, ?int $mediaId): void
    {
        $channel->update(['logo_media_id' => $mediaId]);
        if ($this->isRunning($channel)) {
            $this->rebuild($channel);
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

        // Logo overlay input
        $channel->loadMissing('logoMedia');
        $logo = $channel->logoMedia;
        $hasLogo = $logo && file_exists($logo->filepath);
        if ($hasLogo) {
            $cmd = array_merge($cmd, ['-loop', '1', '-i', $logo->filepath]);
        }

        // Build filter_complex
        $filterParts = [];
        $lastLabel = '0:v';

        // Logo overlay
        if ($hasLogo) {
            $position = $channel->logo_position;
            if (preg_match('/^\d+:\d+$/', $position)) {
                // Custom x:y coordinates — use directly in overlay position
                $filterParts[] = "[{$lastLabel}][1:v]scale2ref=w=main_w*0.12:h=-1[logo][base];[base][logo]overlay=$position[with_logo]";
            } else {
                $position = match ($position) {
                    'top-left' => '20:20',
                    'bottom-left' => '20:H-h-20',
                    'bottom-right' => 'W-w-20:H-h-20',
                    default => 'W-w-20:20',
                };
                $filterParts[] = "[{$lastLabel}][1:v]scale2ref=w=main_w*0.12:h=-1[logo][base];[base][logo]overlay={$position}[with_logo]";
            }
            $lastLabel = 'with_logo';
        }

        // Ticker (scrolling text)
        $tickerText = trim((string) $channel->ticker_text);
        if ($channel->ticker_enabled && $tickerText !== '') {
            $tickerFile = $this->tickerFilePath($channel);
            $escapedTickerFile = str_replace("'", "'\\''", $tickerFile);
            $filterParts[] = "[{$lastLabel}]drawtext=textfile='{$escapedTickerFile}':reload=1:y=h-line_h-10:x=w-mod(max(t*80\\,0)\\,w+tw):fontcolor=white:fontsize=24:box=1:boxcolor=black@0.65:boxborderw=8[with_ticker]";
            $lastLabel = 'with_ticker';
        }

        // Clock overlay
        $filterParts[] = "[{$lastLabel}]drawtext=text='%{localtime\\:%H\\:%M\\:%S}':x=15:y=15:fontcolor=white:fontsize=28:box=1:boxcolor=black@0.5:boxborderw=6[final_video]";
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
        $filterComplex = implode(";\n", $filterParts);

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
