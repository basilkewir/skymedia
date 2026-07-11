<?php

declare(strict_types=1);

namespace App\Services;

use App\Console\Commands\GenerateSlate;
use App\Models\Channel;
use App\Models\DvrSegment;
use App\Models\Recording;
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
 *     output.m3u8 → playout_a.m3u8 or playout_b.m3u8
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

    // ── Switch to live (symlink → live.m3u8, keep fallback alive) ──

    public function switchToLive(Channel $channel): void
    {
        $link = $this->outputPlaylist($channel);
        $dvrDir = $channel->dvr_directory;

        if (! is_dir($dvrDir)) {
            mkdir($dvrDir, 0755, true);
        }

        // Publish live. If live.m3u8 doesn't exist yet (ingest just started
        // or source is dead), point to the fallback playlist so push can
        // start immediately instead of failing with "playlist not ready".
        $liveFile = $dvrDir . '/live.m3u8';
        if (file_exists($liveFile)) {
            $this->atomicPoint($link, 'live.m3u8');
        } else {
            $playoutA = $dvrDir . '/playout_a.m3u8';
            $playoutB = $dvrDir . '/playout_b.m3u8';
            if (file_exists($playoutA)) {
                $this->atomicPoint($link, 'playout_a.m3u8');
            } elseif (file_exists($playoutB)) {
                $this->atomicPoint($link, 'playout_b.m3u8');
            } else {
                $this->atomicPoint($link, 'live.m3u8');
            }
        }
        $channel->update(['playout_status' => 'live']);
        Log::info("[Playout] {$channel->name} switched to live → output.m3u8");
    }

    /**
     * Fully stop the background fallback loop. Used when the channel is
     * stopped or the VOD playlist is being rebuilt from scratch.
     */
    public function stopFallback(Channel $channel): void
    {
        $this->stopFallbackProcess($channel);
        $nextPidFile = $this->ffmpeg->pidFile($channel, 'playout_next');
        $nextPid = $this->ffmpeg->readPid($nextPidFile);
        if ($nextPid > 0) {
            $this->ffmpeg->stopProcess($nextPid);
        }
        $this->ffmpeg->clearPid($nextPidFile);
        $this->cleanupSlot($channel, 'a');
        $this->cleanupSlot($channel, 'b');
    }

    /**
     * Ensure at least one fallback loop slot is running, starting one if
     * both are dead. Returns true if a fallback is available.
     */
    public function ensureFallbackRunning(Channel $channel): bool
    {
        $files = $this->resolveFallbackFiles($channel);
        if (empty($files)) {
            return false;
        }

        // Check if either slot is alive
        $pidFile = $this->ffmpeg->pidFile($channel, 'playout');
        $pid = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0 && $this->ffmpeg->isRunning($pid)) {
            return true;
        }

        $nextPidFile = $this->ffmpeg->pidFile($channel, 'playout_next');
        $nextPid = $this->ffmpeg->readPid($nextPidFile);
        if ($nextPid > 0 && $this->ffmpeg->isRunning($nextPid)) {
            return true;
        }

        // Both dead — start a fresh fallback. switchToFallback will do
        // the warm-standby dance and bring one slot up.
        $branding = $this->ffmpeg->brandingFlags($channel);
        $hasBranding = ! empty($branding['video']);

        foreach (['a', 'b'] as $slot) {
            $this->cleanupSlot($channel, $slot);
        }
        $concatFile = $this->buildConcatList($channel, $files, slot: 'a');

        if ($hasBranding) {
            // Branding requires re-encoding — go straight to re-encode
            $pid = null;
        } else {
            $copyCmd = $this->buildFallbackCommand($channel, $concatFile, useCopy: true, slot: 'a');
            $pid = $this->tryStartFallback($channel, $copyCmd, $pidFile, $this->ffmpeg->logFile($channel, 'playout'));
        }
        if ($pid === null) {
            $loopAsset = $this->buildFallbackLoopAsset($channel, $files, 'a');
            if ($loopAsset === null) {
                return false;
            }
            $pid = $this->tryStartFallback(
                $channel,
                $this->buildFallbackCommand($channel, $loopAsset, useCopy: false, slot: 'a'),
                $pidFile,
                $this->ffmpeg->logFile($channel, 'playout')
            );
        }
        if ($pid === null) {
            return false;
        }

        // Wait for the playlist to appear
        $m3u8Out = $channel->dvr_directory . '/playout_a.m3u8';
        $waited = 0;
        $maxWait = max(6, (int) $channel->segment_duration * 2 + 1);
        while (! file_exists($m3u8Out) && $waited < $maxWait) {
            sleep(1);
            $waited++;
        }
        if (! file_exists($m3u8Out)) {
            if ($pid > 0) {
                $this->ffmpeg->stopProcess($pid);
            }
            $this->ffmpeg->clearPid($pidFile);

            return false;
        }
        $channel->update(['playout_pid' => $pid]);
        Log::info("[Playout] {$channel->name} background fallback started — PID {$pid}");

        return true;
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

    // ── Switch to fallback (warm standby slot, then swap atomically) ──

    public function switchToFallback(Channel $channel): bool
    {
        $files = $this->resolveFallbackFiles($channel);
        if (empty($files)) {
            Log::warning("[Playout] {$channel->name}: no fallback files available");

            return false;
        }

        $link = $this->outputPlaylist($channel);
        $current = is_link($link) ? readlink($link) : null;
        $slot = $current === 'playout_a.m3u8' ? 'b' : 'a';
        $oldPidFile = $this->ffmpeg->pidFile($channel, 'playout');
        $oldPid = $this->ffmpeg->readPid($oldPidFile);
        $hasWorkingFallback = in_array($current, ['playout_a.m3u8', 'playout_b.m3u8'], true)
            && $oldPid > 0 && $this->ffmpeg->isRunning($oldPid);
        $pidFile = $this->ffmpeg->pidFile($channel, 'playout_next');
        $logFile = $this->ffmpeg->logFile($channel, 'playout');

        // Try a stream-copy concat playlist first: it preserves original
        // quality and plays recordings oldest→newest like a TV playlist.
        // If the files are incompatible (e.g. different codec params), fall
        // back to re-encoding a single loopable asset.
        // When branding is configured, skip copy-concat entirely — branding
        // requires re-encoding so the overlay can be applied.
        $branding = $this->ffmpeg->brandingFlags($channel);
        $hasBranding = ! empty($branding['video']);

        $this->cleanupSlot($channel, $slot);
        $concatFile = $this->buildConcatList($channel, $files, slot: $slot);

        if ($hasBranding) {
            // Branding requires re-encoding — go straight to re-encode path
            $pid = null;
        } else {
            $copyCmd = $this->buildFallbackCommand($channel, $concatFile, useCopy: true, slot: $slot);
            $pid = $this->tryStartFallback($channel, $copyCmd, $pidFile, $logFile);
        }

        if ($pid === null) {
            Log::warning("[Playout] {$channel->name}: copy-concat fallback failed, trying re-encode loop");
            $loopAsset = $this->buildFallbackLoopAsset($channel, $files, $slot);
            if ($loopAsset === null) {
                Log::error("[Playout] {$channel->name}: could not build fallback loop asset");
                if (! $hasWorkingFallback) {
                    $channel->update(['playout_pid' => null, 'playout_status' => 'error']);
                }

                return false;
            }
            $pid = $this->tryStartFallback(
                $channel,
                $this->buildFallbackCommand($channel, $loopAsset, useCopy: false, slot: $slot),
                $pidFile,
                $logFile
            );
        }

        if ($pid === null) {
            Log::error("[Playout] {$channel->name} fallback failed to start");
            if (! $hasWorkingFallback) {
                $channel->update(['playout_pid' => null, 'playout_status' => 'error']);
            }

            return false;
        }

        // Wait for the fallback playlist to actually exist before exposing it.
        // ffmpeg's HLS muxer only writes the .m3u8 after the first segment is
        // complete, which can take one segment duration.
        $m3u8Out = $channel->dvr_directory . "/playout_{$slot}.m3u8";
        $waited = 0;
        $maxWait = max(6, (int) $channel->segment_duration * 2 + 1);
        while (! file_exists($m3u8Out) && $waited < $maxWait) {
            sleep(1);
            $waited++;
        }

        if (! file_exists($m3u8Out)) {
            Log::warning("[Playout] {$channel->name} fallback playlist never appeared");
            $nextPid = $this->ffmpeg->readPid($pidFile);
            if ($nextPid > 0) {
                $this->ffmpeg->stopProcess($nextPid);
            }
            $this->ffmpeg->clearPid($pidFile);
            if (! $hasWorkingFallback) {
                $channel->update(['playout_pid' => null, 'playout_status' => 'error']);
            }

            return false;
        }

        // The standby playlist is now primed. Switch in one filesystem rename,
        // then stop the previous fallback process—not the other way around.
        $this->atomicPoint($link, "playout_{$slot}.m3u8");
        if ($oldPid > 0 && $oldPid !== $pid) {
            $this->ffmpeg->stopProcess($oldPid);
        }
        file_put_contents($oldPidFile, (string) $pid);
        $this->ffmpeg->clearPid($pidFile);
        // Keep the retired slot's files until that slot is prepared again.
        // Readers may still have its last playlist open for one refresh cycle.

        $fileList = implode(', ', array_map('basename', $files));
        $channel->update(['playout_pid' => $pid, 'playout_status' => 'fallback']);
        Log::info("[Playout] {$channel->name} fallback playlist started — PID {$pid} — [{$fileList}]");

        return true;
    }

    // ── Stop everything ─────────────────────────────────────────────────

    public function stop(Channel $channel): void
    {
        $this->stopFallbackProcess($channel);
        $nextPidFile = $this->ffmpeg->pidFile($channel, 'playout_next');
        $nextPid = $this->ffmpeg->readPid($nextPidFile);
        if ($nextPid > 0) {
            $this->ffmpeg->stopProcess($nextPid);
        }
        $this->ffmpeg->clearPid($nextPidFile);
        @unlink($this->outputPlaylist($channel));
        $this->cleanupSlot($channel, 'a');
        $this->cleanupSlot($channel, 'b');
        @unlink($channel->dvr_directory . '/fallback_loop.mp4');
        @unlink($channel->dvr_directory . '/fallback_loop_a.mp4');
        @unlink($channel->dvr_directory . '/fallback_loop_b.mp4');
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
        if (! empty($this->resolveFallbackFiles($channel, false))) {
            return true;
        }

        // Otherwise, fallback is possible only if a slate already exists.
        $slate = $channel->dvr_directory . '/slate.mp4';

        return file_exists($slate) && filesize($slate) > 1024;
    }

    /**
     * Ensure a slate MP4 exists for this channel so fallback always has
     * something to play. Called at channel startup.
     */
    public function ensureSlate(Channel $channel): void
    {
        $slate = $channel->dvr_directory . '/slate.mp4';
        if (file_exists($slate) && filesize($slate) > 1024) {
            return;
        }
        try {
            app(GenerateSlate::class)->generateSlate($channel);
            Log::info("[Playout] Slate generated for {$channel->name}");
        } catch (\Throwable $e) {
            Log::error("[Playout] Slate generation failed for {$channel->name}: {$e->getMessage()}");
        }
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

    private function atomicPoint(string $link, string $target): void
    {
        $temporary = $link . '.next';
        @unlink($temporary);
        symlink($target, $temporary);
        rename($temporary, $link);
    }

    private function cleanupSlot(Channel $channel, string $slot): void
    {
        @unlink($channel->dvr_directory . "/playout_{$slot}.m3u8");
        @unlink($channel->dvr_directory . "/playout_concat_{$slot}.txt");
        foreach (glob($channel->dvr_directory . "/playout_{$slot}_*.ts") ?: [] as $file) {
            @unlink($file);
        }
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

    private function buildFallbackCommand(Channel $channel, string $input, bool $useCopy, string $slot): array
    {
        $dvrDir = $channel->dvr_directory;
        $segPattern = "{$dvrDir}/playout_{$slot}_%010d.ts";
        $m3u8Out = "{$dvrDir}/playout_{$slot}.m3u8";
        $segDur = max(1, (int) $channel->segment_duration);

        // Common HLS output flags — omit_endlist is CRITICAL: without it ffmpeg
        // writes EXT-X-ENDLIST when the concat list ends, which causes the push
        // process to see the stream as finished and stop. With -stream_loop -1
        // and omit_endlist the playlist stays "live" forever.
        $hlsFlags = 'delete_segments+omit_endlist+append_list';

        // Check if branding (logo/ticker) is configured for this channel.
        // When branding is present, we MUST re-encode to apply the overlay,
        // so skip the copy-concat path entirely.
        $branding = $this->ffmpeg->brandingFlags($channel);
        $hasBranding = ! empty($branding['video']);

        // LLOD v3: when enabled, force re-encode so keyframes and B-frames are
        // controlled precisely. This guarantees instant playback and clean splits.
        $llodReencode = config('skymedia.llod_v3_reencode_fallback', false);

        // If the operator configured an output codec/bitrate/framerate, enforce
        // it on fallback so uploaded files / playlists match the live push.
        $outputCodec = $channel->push_video_codec ?? 'copy';
        $needsReencode = $hasBranding || $llodReencode || $outputCodec !== 'copy';

        if ($useCopy && ! $needsReencode) {
            return [
                $this->ffmpeg->getBin(),
                '-y', '-loglevel', 'warning', '-stats',
                '-stream_loop', '-1',
                '-re',
                '-safe', '0',
                '-f',    'concat',
                '-i',    $input,
                '-c:v',  'copy',
                '-c:a',  'copy',
                '-f',                    'hls',
                '-hls_time',             (string) $segDur,
                '-hls_list_size',        '10',
                '-hls_flags',            $hlsFlags,
                '-hls_delete_threshold', '3',
                '-hls_segment_type',     'mpegts',
                '-hls_segment_filename', $segPattern,
                '-hls_allow_cache',      '0',
                '-hls_start_number_source', 'epoch',
                // LLOD v3 — force keyframes every 2 seconds for instant
                // playback and clean segment splits on the player side.
                '-force_key_frames',     'expr:gte(t,n_forced*2)',
                // LLOD v3 — skip B-frames to reduce decoder latency.
                '-bf',                   '0',
                $m3u8Out,
            ];
        }

        // Re-encoded fallback: used when copy-concat fails OR when branding
        // overlay (logo/ticker) needs to be baked into the fallback content.
        // Branding is applied here so push always uses -c:v copy (no restart).
        $cmd = [
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            '-stream_loop', '-1',
            '-re',
            '-i',     $input,
        ];

        // Apply branding overlay (logo + ticker) to fallback content, or
        // re-encode according to the channel's configured output settings.
        if ($hasBranding) {
            $cmd = array_merge($cmd, $branding['inputs']);
            $cmd = array_merge($cmd, $branding['video']);
        } else {
            $cmd = array_merge($cmd, $this->ffmpeg->videoEncodeFlags($channel));
        }

        // If branding/LLOD forced re-encode but audio codec is copy, we still
        // need AAC for reliable HLS fallback playback.
        $audioFlags = $this->ffmpeg->audioEncodeFlags($channel);
        $copyAudio = false;
        foreach ($audioFlags as $i => $v) {
            if ($v === '-c:a' && ($audioFlags[$i + 1] ?? null) === 'copy') {
                $copyAudio = true;
                break;
            }
        }
        if (($llodReencode || $hasBranding) && $copyAudio) {
            $audioFlags = [
                '-c:a', 'aac',
                '-b:a', ((int) ($channel->push_audio_bitrate ?? 128)) . 'k',
                '-ar',  (string) (int) ($channel->push_audio_samplerate ?? 48000),
                '-ac',  (string) (int) ($channel->push_audio_channels ?? 2),
            ];
        }
        $cmd = array_merge($cmd, $audioFlags);

        $cmd = array_merge($cmd, [
            '-f',                    'hls',
            '-hls_time',             (string) $segDur,
            '-hls_list_size',        '10',
            '-hls_flags',            $hlsFlags,
            '-hls_delete_threshold', '2',
            '-hls_segment_type',     'mpegts',
            '-hls_segment_filename', $segPattern,
            '-hls_allow_cache',      '0',
            '-hls_start_number_source', 'epoch',
            // LLOD v3 — force keyframes every 2 seconds for instant
            // playback and clean segment splits on the player side.
            '-force_key_frames',     'expr:gte(t,n_forced*2)',
            // LLOD v3 — skip B-frames to reduce decoder latency.
            '-bf',                   '0',
            $m3u8Out,
        ]);

        return $cmd;
    }

    /**
     * Build a single re-encoded MP4 containing all fallback files concatenated.
     * Used only when stream-copy concat fails (incompatible codecs/params).
     * Returns the path to the loop asset, or null on failure.
     */
    private function buildFallbackLoopAsset(Channel $channel, array $files, string $slot = 'a'): ?string
    {
        $dvrDir = $channel->dvr_directory;
        $output = "{$dvrDir}/fallback_loop_{$slot}.mp4";

        // Always build a proper concat even for a single file — re-encoding
        // normalises codec params so the loop plays cleanly.
        $concatFile = $this->buildConcatList($channel, $files, repeat: 1, slot: $slot);

        // Re-encode the loop asset to the channel's configured output settings
        // so fallback quality matches the live push.
        $cmd = array_merge([
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            '-safe', '0',
            '-f',    'concat',
            '-i',    $concatFile,
        ], $this->ffmpeg->videoEncodeFlags($channel), $this->ffmpeg->audioEncodeFlags($channel), [
            '-movflags', '+faststart',
            $output,
        ]);

        $proc = new Process($cmd);
        $proc->setTimeout(300);
        $proc->run();

        if (! $proc->isSuccessful() || ! file_exists($output) || filesize($output) < 1024) {
            Log::error("[Playout] {$channel->name} fallback loop asset failed: " . $proc->getErrorOutput());

            // Last resort: return the first file so something plays
            return $files[0] ?? null;
        }

        return $output;
    }

    /**
     * Write a concat list file: recordings oldest→newest, looped enough times
     * to cover at least 2 hours of continuous playout without restarting ffmpeg.
     * Returns the path to the concat file.
     */
    private function buildConcatList(Channel $channel, array $files, int $repeat = 0, string $slot = 'a'): string
    {
        $path = $channel->dvr_directory . "/playout_concat_{$slot}.txt";

        if ($repeat <= 0) {
            $repeat = $this->fallbackPlaylistRepetitions($files);
        }

        $lines = [];
        for ($i = 0; $i < $repeat; $i++) {
            foreach ($files as $f) {
                $lines[] = "file '" . str_replace("'", "'\\''", $f) . "'";
            }
        }

        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    /**
     * How many times to repeat the file list so the concat covers at least
     * 2 hours. This prevents ffmpeg from reaching the end of the concat list
     * and exiting before -stream_loop kicks in for the next iteration.
     * Minimum 3 repetitions regardless of duration.
     */
    private function fallbackPlaylistRepetitions(array $files): int
    {
        $targetSeconds = 7200; // 2 hours
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

        return max(3, min($repeat, 100));
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
     * Returns ordered list of files to loop: recordings oldest→newest (by
     * completion time from DB), then DVR segments, then slate as last resort.
     *
     * Ordering rules:
     *  1. Operator-curated VOD playlist (sort_order ASC) — takes full precedence
     *  2. Operator-uploaded single VOD (fallback_vod_name) — single file loop
     *  3. Completed recordings from DB ordered by completed_at ASC (oldest→newest)
     *     so playout plays like a chronological TV archive
     *  4. DVR rolling-window segments (sequence ASC) if no recordings exist
     *  5. Slate MP4 appended only when no recordings AND no DVR segments exist
     *
     * Set $generateSlate=false when only probing availability (no side effects).
     */
    private function resolveFallbackFiles(Channel $channel, bool $generateSlate = true): array
    {
        // 1. Operator-curated VOD playlist
        $playlist = $channel->media()->where('type', 'vod')->where('is_active', true)->orderBy('sort_order')->get();
        $files = [];
        foreach ($playlist as $media) {
            if (file_exists($media->filepath) && filesize($media->filepath) > 1024) {
                $files[] = $media->filepath;
            }
        }
        if ($files !== []) {
            return $files;
        }

        // 2. Single operator-uploaded VOD
        if ($channel->fallback_vod_name
            && $channel->fallback_recording_path
            && file_exists($channel->fallback_recording_path)
            && filesize($channel->fallback_recording_path) > 1024) {
            return [$channel->fallback_recording_path];
        }

        // 3. Completed recordings from DB, oldest→newest by completed_at
        //    Using DB ordering is more reliable than filemtime which can be
        //    affected by filesystem operations (copies, moves, etc.)
        $recordings = Recording::where('channel_id', $channel->id)
            ->where('status', 'completed')
            ->orderBy('completed_at', 'asc')
            ->get();

        foreach ($recordings as $rec) {
            if (file_exists($rec->filepath) && filesize($rec->filepath) > 1024) {
                $files[] = $rec->filepath;
            }
        }

        // Also pick up any rec_*.mp4 files on disk not yet in DB (e.g. after
        // a container restart before finalization ran). Sort by filename which
        // encodes the recording timestamp (rec_YYYYMMDD_HHMMSS.mp4).
        if (empty($files)) {
            $diskRecs = glob($channel->dvr_directory . '/rec_*.mp4') ?: [];
            sort($diskRecs, SORT_NATURAL); // natural sort = chronological by filename
            foreach ($diskRecs as $f) {
                if (filesize($f) > 1024) {
                    $files[] = $f;
                }
            }
        }

        if (! empty($files)) {
            // We have recordings — return them without appending slate.
            // The playlist loops oldest→newest then repeats from the start.
            return $files;
        }

        // 4. DVR rolling-window segments (no recordings yet)
        $segments = DvrSegment::where('channel_id', $channel->id)
            ->where('is_available', true)
            ->orderBy('sequence', 'asc')
            ->get();

        foreach ($segments as $seg) {
            if (file_exists($seg->filepath) && filesize($seg->filepath) > 1024) {
                $files[] = $seg->filepath;
            }
        }

        if (empty($files)) {
            $diskSegs = glob($channel->dvr_directory . '/seg_*.ts') ?: [];
            natsort($diskSegs);
            foreach ($diskSegs as $f) {
                if (filesize($f) > 1024) {
                    $files[] = $f;
                }
            }
        }

        if (! empty($files)) {
            return $files;
        }

        // 5. Last resort: slate only
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
