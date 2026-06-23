<?php

declare(strict_types=1);

namespace App\Services;

use App\Console\Commands\GenerateSlate;
use App\Models\Channel;
use App\Models\DvrSegment;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * PlayoutService — manages the channel output playlist.
 *
 * Push ALWAYS reads output.m3u8 (a stable path that never changes).
 * This service manages what output.m3u8 points to via a symlink:
 *
 *   LIVE mode:
 *     output.m3u8 → live.m3u8  (written by ingest — no ffmpeg process)
 *     playout_status = 'live', playout_pid = null
 *
 *   FALLBACK mode:
 *     output.m3u8 → playout.m3u8  (written by ffmpeg looping a recording)
 *     playout_status = 'fallback', playout_pid = <pid>
 *
 * The symlink is swapped atomically. Push never restarts — ffmpeg's HLS
 * demuxer picks up the new playlist content on its next refresh cycle
 * (typically within one segment duration).
 */
class PlayoutService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    /**
     * Returns the stable playlist path that push always reads.
     * This path NEVER changes — only its symlink target does.
     */
    public function outputPlaylist(Channel $channel): string
    {
        return $channel->dvr_directory . '/output.m3u8';
    }

    // ── Switch to live (symlink → live.m3u8, kill any fallback loop) ──

    public function switchToLive(Channel $channel): void
    {
        $link = $this->outputPlaylist($channel);
        $dvrDir = $channel->dvr_directory;
        $live = $dvrDir . '/live.m3u8';

        if (! is_dir($dvrDir)) {
            mkdir($dvrDir, 0755, true);
        }

        @unlink($link);
        // Always point the symlink at live.m3u8; even if it does not exist yet,
        // it will become valid as soon as ingest writes the first playlist.
        symlink('live.m3u8', $link);

        $this->stopFallbackProcess($channel);
        $channel->update(['playout_pid' => null, 'playout_status' => 'live']);
        Log::info("[Playout] {$channel->name} switched to live → output.m3u8");
    }

    /**
     * True when output.m3u8 exists and resolves to live.m3u8.
     */
    public function isLiveOutput(Channel $channel): bool
    {
        $link = $this->outputPlaylist($channel);
        if (! is_link($link)) {
            return false;
        }

        $target = readlink($link);

        return $target === 'live.m3u8';
    }

    // ── Switch to fallback (start ffmpeg loop, symlink → playout.m3u8) ──

    public function switchToFallback(Channel $channel): bool
    {
        $files = $this->resolveFallbackFiles($channel);
        if (empty($files)) {
            Log::warning("[Playout] {$channel->name}: no fallback files available");

            return false;
        }

        $this->stopFallbackProcess($channel);

        $pidFile = $this->ffmpeg->pidFile($channel, 'playout');
        $logFile = $this->ffmpeg->logFile($channel, 'playout');

        // Try a stream-copy concat playlist first: it preserves original
        // quality and plays recordings oldest→newest like a TV playlist.
        // If the files are incompatible (e.g. different codec params), fall
        // back to re-encoding a single loopable asset.
        $concatFile = $this->buildConcatList($channel, $files);
        $copyCmd = $this->buildFallbackCommand($channel, $concatFile, useCopy: true);

        $pid = $this->tryStartFallback($channel, $copyCmd, $pidFile, $logFile);

        if ($pid === null) {
            Log::warning("[Playout] {$channel->name}: copy-concat fallback failed, trying re-encode loop");
            $loopAsset = $this->buildFallbackLoopAsset($channel, $files);
            if ($loopAsset === null) {
                Log::error("[Playout] {$channel->name}: could not build fallback loop asset");
                $channel->update(['playout_pid' => null, 'playout_status' => 'error']);

                return false;
            }
            $pid = $this->tryStartFallback(
                $channel,
                $this->buildFallbackCommand($channel, $loopAsset, useCopy: false),
                $pidFile,
                $logFile
            );
        }

        if ($pid === null) {
            Log::error("[Playout] {$channel->name} fallback failed to start");
            $channel->update(['playout_pid' => null, 'playout_status' => 'error']);

            return false;
        }

        // Wait for the fallback playlist to actually exist before exposing it.
        // ffmpeg's HLS muxer only writes the .m3u8 after the first segment is
        // complete, which can take one segment duration.
        $m3u8Out = $channel->dvr_directory . '/playout.m3u8';
        $waited = 0;
        $maxWait = max(6, (int) $channel->segment_duration * 2 + 1);
        while (! file_exists($m3u8Out) && $waited < $maxWait) {
            sleep(1);
            $waited++;
        }

        if (! file_exists($m3u8Out)) {
            Log::warning("[Playout] {$channel->name} fallback playlist never appeared");
            $this->stopFallbackProcess($channel);
            $channel->update(['playout_pid' => null, 'playout_status' => 'error']);

            return false;
        }

        $link = $this->outputPlaylist($channel);
        @unlink($link);
        symlink('playout.m3u8', $link);

        $fileList = implode(', ', array_map('basename', $files));
        $channel->update(['playout_pid' => $pid, 'playout_status' => 'fallback']);
        Log::info("[Playout] {$channel->name} fallback playlist started — PID {$pid} — [{$fileList}]");

        return true;
    }

    // ── Stop everything ─────────────────────────────────────────────────

    public function stop(Channel $channel): void
    {
        $this->stopFallbackProcess($channel);
        @unlink($this->outputPlaylist($channel));
        @unlink($channel->dvr_directory . '/playout.m3u8');
        @unlink($channel->dvr_directory . '/fallback_loop.mp4');
        @unlink($channel->dvr_directory . '/playout_concat.txt');
        foreach (glob($channel->dvr_directory . '/playout_*.ts') ?: [] as $f) {
            @unlink($f);
        }
        $channel->update(['playout_pid' => null, 'playout_status' => 'stopped']);
    }

    // ── State ───────────────────────────────────────────────────────────

    public function isFallbackRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'playout'));

        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    public function hasFallback(Channel $channel): bool
    {
        // Recordings or DVR segments provide real fallback content.
        if (!empty($this->resolveFallbackFiles($channel, false))) {
            return true;
        }

        // Otherwise, fallback is possible only if a slate already exists.
        $slate = $channel->dvr_directory . '/slate.mp4';

        return file_exists($slate) && filesize($slate) > 1024;
    }

    // ── Internal ────────────────────────────────────────────────────────

    private function stopFallbackProcess(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'playout');
        $pid = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }
        $this->ffmpeg->clearPid($pidFile);
    }

    private function tryStartFallback(Channel $channel, array $cmd, string $pidFile, string $logFile): ?int
    {
        try {
            return $this->ffmpeg->startProcess($cmd, $pidFile, $logFile, 6);
        } catch (\Throwable $e) {
            Log::debug("[Playout] {$channel->name} fallback start attempt failed: {$e->getMessage()}");

            return null;
        }
    }

    private function buildFallbackCommand(Channel $channel, string $input, bool $useCopy): array
    {
        $dvrDir = $channel->dvr_directory;
        $segPattern = "{$dvrDir}/playout_%05d.ts";
        $m3u8Out = "{$dvrDir}/playout.m3u8";
        $segDur = max(1, (int) $channel->segment_duration);

        if ($useCopy) {
            // Stream-copy concat: preserves original encoding and creates a
            // real playlist that plays recordings oldest→newest.
            return [
                $this->ffmpeg->getBin(),
                '-y', '-loglevel', 'warning', '-stats',
                '-re',
                '-safe', '0',
                '-f',    'concat',
                '-i',    $input,
                '-c:v',  'copy',
                '-c:a',  'copy',
                '-f',                    'hls',
                '-hls_time',             (string) $segDur,
                '-hls_list_size',        '0',
                '-hls_flags',            'omit_endlist',
                '-hls_segment_type',     'mpegts',
                '-hls_segment_filename', $segPattern,
                '-hls_allow_cache',      '0',
                $m3u8Out,
            ];
        }

        // Re-encoded single-asset loop. Used only when copy-concat fails.
        return [
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            '-stream_loop', '-1',
            '-re',
            '-i',     $input,
            '-c:v', 'copy',
            '-c:a', 'copy',
            '-f',                    'hls',
            '-hls_time',             (string) $segDur,
            '-hls_list_size',        '10',
            '-hls_flags',            'delete_segments+append_list',
            '-hls_delete_threshold', '2',
            '-hls_segment_type',     'mpegts',
            '-hls_segment_filename', $segPattern,
            '-hls_allow_cache',      '0',
            $m3u8Out,
        ];
    }

    /**
     * Build a single MP4 that contains all fallback files concatenated.
     * Used as a last resort when stream-copy concat is not possible.
     * Returns the path to the loop asset, or null on failure.
     */
    private function buildFallbackLoopAsset(Channel $channel, array $files): ?string
    {
        $dvrDir = $channel->dvr_directory;
        $output = "{$dvrDir}/fallback_loop.mp4";

        // If there is only one file, use it directly.
        if (count($files) === 1) {
            return $files[0];
        }

        $concatFile = $this->buildConcatList($channel, $files, repeat: 1);

        $cmd = [
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            '-safe', '0',
            '-f',    'concat',
            '-i',    $concatFile,
            '-c:v',  'libx264',
            '-preset', 'veryfast',
            '-tune', 'film',
            '-crf',  '23',
            '-pix_fmt', 'yuv420p',
            '-g',    '60',
            '-keyint_min', '60',
            '-sc_threshold', '0',
            '-c:a',  'aac',
            '-b:a',  '128k',
            '-movflags', '+faststart',
            $output,
        ];

        $proc = new Process($cmd);
        $proc->setTimeout(120);
        $proc->run();

        if (! $proc->isSuccessful() || ! file_exists($output) || filesize($output) < 1024) {
            Log::error("[Playout] {$channel->name} fallback loop asset failed: " . $proc->getErrorOutput());

            return $files[0] ?? null;
        }

        return $output;
    }

    /**
     * Write a concat list file: recordings oldest-first, then slate if available.
     * Repeats the whole playlist enough times to give the player a long,
     * playlist-style buffer. Returns the path to the concat file.
     */
    private function buildConcatList(Channel $channel, array $files, int $repeat = 0): string
    {
        $path = $channel->dvr_directory . '/playout_concat.txt';

        if ($repeat <= 0) {
            $repeat = $this->fallbackPlaylistRepetitions($files, $channel);
        }

        $repeated = [];
        for ($i = 0; $i < $repeat; $i++) {
            foreach ($files as $f) {
                $repeated[] = $f;
            }
        }

        $lines = array_map(fn ($f) => "file '" . str_replace("'", "'\\''", $f) . "'", $repeated);
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    /**
     * How many times to repeat the fallback file list so the generated
     * playlist covers a reasonable outage window (10–30 min).
     */
    private function fallbackPlaylistRepetitions(array $files, Channel $channel): int
    {
        $targetSeconds = min(1800, max(600, (int) $channel->record_duration * 6));

        $totalDuration = 0.0;
        foreach ($files as $f) {
            $dur = $this->probeDuration($f);
            if ($dur > 0) {
                $totalDuration += $dur;
            }
        }

        if ($totalDuration <= 0) {
            return 10;
        }

        $repeat = (int) ceil($targetSeconds / $totalDuration);

        return min(max($repeat, 2), 50);
    }

    private function probeDuration(string $file): float
    {
        try {
            $proc = new Process([
                config('skymedia.ffprobe_binary', 'ffprobe'),
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $file,
            ]);
            $proc->setTimeout(8);
            $proc->run();

            $out = trim($proc->getOutput());
            if ($proc->isSuccessful() && is_numeric($out)) {
                return (float) $out;
            }
        } catch (\Throwable) {
            // ignore
        }

        return 0.0;
    }

    /**
     * Returns ordered list of files to loop: recordings oldest→newest, then DVR
     * segments, then slate. Set $generateSlate to false when only probing.
     */
    private function resolveFallbackFiles(Channel $channel, bool $generateSlate = true): array
    {
        $files = [];

        // Collect all completed recordings, oldest first
        $recs = glob($channel->dvr_directory . '/rec_*.mp4') ?: [];
        usort($recs, fn ($a, $b) => filemtime($a) - filemtime($b)); // oldest first
        foreach ($recs as $f) {
            if (file_exists($f) && filesize($f) > 1024) {
                $files[] = $f;
            }
        }

        // No recordings? Use the DVR rolling-window segments as fallback.
        if (empty($files)) {
            $segments = DvrSegment::where('channel_id', $channel->id)
                ->where('is_available', true)
                ->orderBy('sequence')
                ->get();

            foreach ($segments as $seg) {
                if (file_exists($seg->filepath) && filesize($seg->filepath) > 1024) {
                    $files[] = $seg->filepath;
                }
            }

            // Fall back to a raw disk glob if the database has no entries yet.
            if (empty($files)) {
                $diskFiles = glob($channel->dvr_directory . '/seg_*.ts') ?: [];
                natsort($diskFiles);
                foreach ($diskFiles as $f) {
                    if (filesize($f) > 1024) {
                        $files[] = $f;
                    }
                }
            }
        }

        // Append slate as final entry so there is always something to show
        $slate = $channel->dvr_directory . '/slate.mp4';
        if ($generateSlate && (! file_exists($slate) || filesize($slate) < 1024)) {
            try {
                app(GenerateSlate::class)->generateSlate($channel);
            } catch (\Throwable $e) {
                Log::error("[Playout] Slate generation failed: {$e->getMessage()}");
            }
        }
        if (file_exists($slate) && filesize($slate) > 1024) {
            $files[] = $slate;
        }

        return $files;
    }
}
