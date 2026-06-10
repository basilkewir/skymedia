<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class FFmpegService
{
    protected string $ffmpegBin;
    protected string $ffprobeBin;

    public function __construct()
    {
        $this->ffmpegBin  = config('skymedia.ffmpeg_binary', 'ffmpeg');
        $this->ffprobeBin = config('skymedia.ffprobe_binary', 'ffprobe');
    }

    // ===================================================================
    //  COMMAND BUILDERS
    // ===================================================================

    /**
     * INGEST ONLY: source → HLS segments on disk (DVR recording)
     *
     * - hls_list_size 0  : ffmpeg never deletes a segment itself
     * - append_list       : keeps extending the playlist
     * - omit_endlist      : marks playlist as live (no #EXT-X-ENDLIST)
     * - PHP enforces the rolling window via DVRService::enforceWindow()
     */
    public function buildIngestCommand(Channel $channel): array
    {
        $dvrDir     = $channel->dvr_directory;
        $m3u8       = "{$dvrDir}/live.m3u8";
        $segPattern = "{$dvrDir}/seg_%05d.ts";

        return array_merge(
            [$this->ffmpegBin, '-y', '-loglevel', 'warning'],
            $this->inputFlags($channel),
            // Always copy streams to disk — no re-encoding on ingest
            ['-c:v', 'copy', '-c:a', 'copy'],
            [
                '-f',                    'hls',
                '-hls_time',             (string) $channel->segment_duration,
                '-hls_list_size',        '0',
                '-hls_flags',            'append_list+omit_endlist',
                '-hls_segment_type',     'mpegts',
                '-hls_segment_filename', $segPattern,
                '-start_number',         '0',
                $m3u8,
            ]
        );
    }

    /**
     * PUSH: DVR HLS → encode → RTMP/SRT
     *
     * Always reads from live.m3u8 (DVR buffer). When the source is live
     * the playlist is continuously updated so this process stays near-live.
     * When the source is offline the playlist freezes and ffmpeg loops it
     * via -stream_loop / -re keeping the push alive from stored segments.
     *
     * Encoding is fully per-channel: copy or transcode video/audio with
     * user-specified bitrates, resolution, framerate, codec, samplerate.
     */
    public function buildPushCommand(Channel $channel): array
    {
        $m3u8 = $channel->dvr_directory . '/live.m3u8';

        return array_merge(
            [$this->ffmpegBin, '-y', '-loglevel', 'warning'],
            [
                '-fflags',              '+genpts+igndts',
                '-re',
                '-live_start_index',   '-3',
                '-allowed_extensions', 'ALL',
                '-protocol_whitelist', 'file,crypto,data,http,https,tcp,tls',
                '-i',                  $m3u8,
            ],
            $this->videoEncodeFlags($channel),
            $this->audioEncodeFlags($channel),
            ['-f', $channel->push_protocol === 'srt' ? 'mpegts' : 'flv'],
            $channel->push_protocol === 'rtmp' ? ['-flvflags', 'no_duration_filesize'] : [],
            [$this->pushUrl($channel)]
        );
    }

    /**
     * DVR FALLBACK PUSH: concat.txt → encode → RTMP/SRT (looping)
     *
     * Used only when live.m3u8 does not exist at all (e.g. channel never
     * went live). Loops the stored segments indefinitely.
     */
    public function buildDvrPlaybackCommand(Channel $channel): array
    {
        $concatFile = $channel->dvr_directory . '/concat.txt';

        return array_merge(
            [$this->ffmpegBin, '-y', '-loglevel', 'warning'],
            ['-stream_loop', '-1', '-safe', '0', '-f', 'concat', '-re', '-i', $concatFile],
            $this->videoEncodeFlags($channel),
            $this->audioEncodeFlags($channel),
            ['-f', $channel->push_protocol === 'srt' ? 'mpegts' : 'flv'],
            $channel->push_protocol === 'rtmp' ? ['-flvflags', 'no_duration_filesize'] : [],
            [$this->pushUrl($channel)]
        );
    }

    // ===================================================================
    //  PROCESS MANAGEMENT
    // ===================================================================

    public function startProcess(array $command, string $pidFile, string $logFile): int
    {
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);

        $pidDir = dirname($pidFile);
        if (!is_dir($pidDir)) mkdir($pidDir, 0755, true);

        $cmd   = implode(' ', array_map('escapeshellarg', $command));
        $shell = "nohup {$cmd} >> " . escapeshellarg($logFile) . " 2>&1 & echo \$!";

        $pid = (int) trim(shell_exec($shell));

        if ($pid > 0) {
            file_put_contents($pidFile, $pid);
            usleep(500_000); // 500ms — give ffmpeg time to open input
            if (!$this->isRunning($pid)) {
                $this->clearPid($pidFile);
                return 0;
            }
        }

        return $pid;
    }

    public function stopProcess(int $pid): void
    {
        if ($pid <= 0) return;

        if (function_exists('posix_kill')) {
            posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
        } else {
            exec("kill {$pid} 2>/dev/null");
        }

        $waited = 0;
        while ($this->isRunning($pid) && $waited < 60) {
            usleep(100_000);
            $waited++;
        }

        if ($this->isRunning($pid)) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, defined('SIGKILL') ? SIGKILL : 9);
            } else {
                exec("kill -9 {$pid} 2>/dev/null");
            }
        }
    }

    public function isRunning(int $pid): bool
    {
        if ($pid <= 0) return false;
        if (is_dir("/proc/{$pid}")) return true;
        if (function_exists('posix_kill')) return posix_kill($pid, 0);
        exec("ps -p {$pid} 2>/dev/null", $out, $code);
        return $code === 0;
    }

    // ===================================================================
    //  SOURCE HEALTH
    // ===================================================================

    public function checkSourceHealth(Channel $channel): bool
    {
        try {
            $args = [
                $this->ffprobeBin,
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_streams',
                '-timeout', '6000000',
            ];

            if (in_array($channel->source_type, ['udp', 'mpegts', 'srt'])) {
                array_push($args, '-analyzeduration', '2000000', '-probesize', '3000000');
            }

            if ($channel->source_type === 'hls') {
                array_push($args, '-allowed_extensions', 'ALL');
            }

            $args[] = $channel->source_url;

            $proc = new Process($args);
            $proc->setTimeout(10);
            $proc->run();

            if (!$proc->isSuccessful()) return false;

            $data = json_decode($proc->getOutput(), true);
            return !empty($data['streams']);
        } catch (\Throwable $e) {
            Log::debug("Health check error [{$channel->name}]: {$e->getMessage()}");
            return false;
        }
    }

    public function probeStream(Channel $channel): array
    {
        $args = [
            $this->ffprobeBin,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            '-timeout', '8000000',
        ];

        if ($channel->source_type === 'hls') {
            array_push($args, '-allowed_extensions', 'ALL');
        }

        $args[] = $channel->source_url;

        $proc = new Process($args);
        $proc->setTimeout(15);
        $proc->run();

        if (!$proc->isSuccessful()) {
            return ['error' => $proc->getErrorOutput()];
        }

        return json_decode($proc->getOutput(), true) ?? [];
    }

    // ===================================================================
    //  HLS READINESS
    // ===================================================================

    public function hlsReady(Channel $channel, int $minSegments = 2): bool
    {
        $m3u8 = $channel->dvr_directory . '/live.m3u8';
        if (!file_exists($m3u8)) return false;
        return count(glob($channel->dvr_directory . '/seg_*.ts') ?: []) >= $minSegments;
    }

    // ===================================================================
    //  PID / FILE HELPERS
    // ===================================================================

    public function pidFile(Channel $channel, string $type = 'ingest'): string
    {
        $dir = storage_path('app/pids');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return "{$dir}/{$type}_{$channel->id}.pid";
    }

    public function logFile(Channel $channel, string $type = 'ingest'): string
    {
        $dir = config('skymedia.log_base_path', storage_path('logs/streams'));
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return "{$dir}/{$type}_{$channel->id}.log";
    }

    public function readPid(string $pidFile): int
    {
        if (!file_exists($pidFile)) return 0;
        return (int) trim(file_get_contents($pidFile));
    }

    public function clearPid(string $pidFile): void
    {
        @unlink($pidFile);
    }

    public function readLogTail(string $logFile, int $lines = 80): string
    {
        if (!file_exists($logFile)) return '';
        $out = [];
        exec("tail -{$lines} " . escapeshellarg($logFile) . " 2>/dev/null", $out);
        return implode("\n", $out);
    }

    // ===================================================================
    //  INTERNAL — INPUT FLAGS
    // ===================================================================

    protected function inputFlags(Channel $channel): array
    {
        $probesize = in_array($channel->source_type, ['udp', 'mpegts']) ? '5000000' : '1000000';
        $analyze   = in_array($channel->source_type, ['udp', 'mpegts']) ? '3000000' : '1000000';

        return match ($channel->source_type) {
            'udp', 'mpegts' => [
                '-fflags',           '+genpts+discardcorrupt',
                '-probesize',        $probesize,
                '-analyzeduration',  $analyze,
                '-timeout',          '10000000',
                '-i',                $channel->source_url,
            ],
            'hls' => [
                '-re',
                '-fflags',           '+genpts',
                '-probesize',        $probesize,
                '-analyzeduration',  $analyze,
                '-allowed_extensions', 'ALL',
                '-timeout',          '15000000',
                '-i',                $channel->source_url,
            ],
            'srt' => [
                '-fflags',           '+genpts+discardcorrupt',
                '-probesize',        $probesize,
                '-analyzeduration',  $analyze,
                '-i',                "srt://{$this->parseSrtUrl($channel->source_url)}?timeout=8000000&latency=" . (config('skymedia.srt_latency', 200) * 1000),
            ],
            default => [
                '-fflags',           '+genpts+discardcorrupt',
                '-probesize',        $probesize,
                '-analyzeduration',  $analyze,
                '-timeout',          '10000000',
                '-i',                $channel->source_url,
            ],
        };
    }

    // ===================================================================
    //  INTERNAL — ENCODING FLAGS
    // ===================================================================

    /**
     * Build video encode flags for the push process.
     *
     * push_video_codec:
     *   'copy'    — pass-through (no re-encode)
     *   'h264'    — libx264 with CBR-ish settings
     *   'h265'    — libx265
     *   'vp8'     — libvpx
     *   'vp9'     — libvpx-vp9
     *
     * push_video_bitrate: target kbps (null = codec default)
     * push_resolution:    WxH string  (null = source resolution)
     * push_framerate:     fps int     (null = source fps)
     */
    protected function videoEncodeFlags(Channel $channel): array
    {
        $codec = $channel->push_video_codec ?? 'copy';

        if ($codec === 'copy') {
            return ['-c:v', 'copy'];
        }

        $ffCodec = match ($codec) {
            'h264'  => 'libx264',
            'h265'  => 'libx265',
            'vp8'   => 'libvpx',
            'vp9'   => 'libvpx-vp9',
            default => 'libx264',
        };

        $flags = ['-c:v', $ffCodec];

        if ($channel->push_video_bitrate) {
            $kbps = (int) $channel->push_video_bitrate;
            // CBR-ish: set bitrate, maxrate = 120%, bufsize = 2× bitrate
            $flags = array_merge($flags, [
                '-b:v',      "{$kbps}k",
                '-maxrate',  (int) ($kbps * 1.2) . 'k',
                '-bufsize',  ($kbps * 2) . 'k',
            ]);
        }

        if ($channel->push_resolution) {
            $flags = array_merge($flags, ['-vf', "scale={$channel->push_resolution}"]);
        }

        if ($channel->push_framerate) {
            $flags = array_merge($flags, ['-r', (string) $channel->push_framerate]);
        }

        // H.264/H.265 quality preset for low-latency broadcast
        if (in_array($codec, ['h264', 'h265'])) {
            $flags = array_merge($flags, [
                '-preset',  'veryfast',
                '-tune',    'zerolatency',
                '-g',       (string) (($channel->push_framerate ?? 25) * 2), // keyframe every 2s
                '-keyint_min', (string) ($channel->push_framerate ?? 25),
                '-sc_threshold', '0',
            ]);
        }

        return $flags;
    }

    /**
     * Build audio encode flags for the push process.
     *
     * push_audio_codec:
     *   'copy'   — pass-through
     *   'aac'    — native FFmpeg AAC (LC)
     *   'mp3'    — libmp3lame
     *   'opus'   — libopus
     *   'ac3'    — AC-3 (Dolby Digital)
     *
     * push_audio_bitrate:    kbps
     * push_audio_samplerate: Hz  (44100, 48000, etc.)
     * push_audio_channels:   1=mono, 2=stereo, 6=5.1
     */
    protected function audioEncodeFlags(Channel $channel): array
    {
        $codec = $channel->push_audio_codec ?? 'aac';

        if ($codec === 'copy') {
            return ['-c:a', 'copy'];
        }

        $ffCodec = match ($codec) {
            'aac'   => 'aac',
            'mp3'   => 'libmp3lame',
            'opus'  => 'libopus',
            'ac3'   => 'ac3',
            default => 'aac',
        };

        $bitrate    = (int) ($channel->push_audio_bitrate ?? 128);
        $samplerate = (int) ($channel->push_audio_samplerate ?? 44100);
        $channels   = (int) ($channel->push_audio_channels ?? 2);

        return [
            '-c:a',  $ffCodec,
            '-b:a',  "{$bitrate}k",
            '-ar',   (string) $samplerate,
            '-ac',   (string) $channels,
        ];
    }

    // ===================================================================
    //  INTERNAL — PUSH URL
    // ===================================================================

    protected function pushUrl(Channel $channel): string
    {
        if ($channel->push_protocol === 'srt') {
            $latency = config('skymedia.srt_latency', 200) * 1000;
            return "srt://{$this->parseSrtUrl($channel->push_target)}?latency={$latency}&mode=caller";
        }
        return $channel->push_target;
    }

    protected function parseSrtUrl(string $url): string
    {
        return preg_replace('#^srt://#', '', $url);
    }
}
