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

    public function getBin(): string { return $this->ffmpegBin; }

    // ===================================================================
    //  COMMAND BUILDERS
    // ===================================================================

    /**
     * INGEST: source → HLS segments on disk
     *
     * Uses delete_segments=0 so ffmpeg never removes files itself.
     * DVRService enforces the rolling window by deleting old files.
     * No -re flag on live sources (UDP/SRT/RTMP) — they push at their own rate.
     * -re only on HLS pull sources to avoid hammering the upstream server.
     */
    public function buildIngestCommand(Channel $channel): array
    {
        $dvrDir     = $channel->dvr_directory;
        $m3u8       = "{$dvrDir}/live.m3u8";
        $segPattern = "{$dvrDir}/seg_%05d.ts";

        $cmd = [
            $this->ffmpegBin,
            '-y',
            '-loglevel', 'warning',
            '-stats',
        ];

        // Input flags — source-type specific
        foreach ($this->inputFlags($channel) as $flag) {
            $cmd[] = $flag;
        }

        // Always copy on ingest — no re-encoding, lowest CPU
        $cmd = array_merge($cmd, [
            '-c:v', 'copy',
            '-c:a', 'copy',
            // HLS mux options
            '-f',                    'hls',
            '-hls_time',             (string) max(1, (int) $channel->segment_duration),
            '-hls_list_size',        '0',          // keep all segments in playlist
            '-hls_flags',            'delete_segments+omit_endlist',
            '-hls_delete_threshold', '1',          // ffmpeg keeps 1 extra before deleting
            '-hls_segment_type',     'mpegts',
            '-hls_segment_filename', $segPattern,
            '-hls_allow_cache',      '0',
            '-start_number',         '0',
            $m3u8,
        ]);

        return $cmd;
    }

    /**
     * PUSH: reads live.m3u8 → encode → RTMP/SRT
     * -live_start_index -3 : start from 3 segments before the end (near-live)
     */
    public function buildPushCommand(Channel $channel): array
    {
        $m3u8 = $channel->dvr_directory . '/live.m3u8';

        return array_merge(
            [
                $this->ffmpegBin, '-y', '-loglevel', 'warning', '-stats',
                '-fflags',              '+genpts+igndts',
                '-re',
                '-live_start_index',    '-3',
                '-allowed_extensions',  'ALL',
                '-protocol_whitelist',  'file,crypto,data,http,https,tcp,tls',
                '-i',                   $m3u8,
            ],
            $this->videoEncodeFlags($channel),
            $this->audioEncodeFlags($channel),
            ['-f', $channel->push_protocol === 'srt' ? 'mpegts' : 'flv'],
            $channel->push_protocol === 'rtmp' ? ['-flvflags', 'no_duration_filesize'] : [],
            [$this->pushUrl($channel)]
        );
    }

    /**
     * DVR LOOP: concat.txt → encode → RTMP/SRT (infinite loop)
     */
    public function buildDvrPlaybackCommand(Channel $channel): array
    {
        return array_merge(
            [
                $this->ffmpegBin, '-y', '-loglevel', 'warning', '-stats',
                '-stream_loop', '-1',
                '-safe', '0',
                '-f', 'concat',
                '-re',
                '-i', $channel->dvr_directory . '/concat.txt',
            ],
            $this->videoEncodeFlags($channel),
            $this->audioEncodeFlags($channel),
            ['-f', $channel->push_protocol === 'srt' ? 'mpegts' : 'flv'],
            $channel->push_protocol === 'rtmp' ? ['-flvflags', 'no_duration_filesize'] : [],
            [$this->pushUrl($channel)]
        );
    }

    /**
     * RECORDING FALLBACK: loops rec_*.mp4 → encode → RTMP/SRT
     */
    public function buildRecordingFallbackCommand(Channel $channel, string $recordingFile): array
    {
        return array_merge(
            [
                $this->ffmpegBin, '-y', '-loglevel', 'warning', '-stats',
                '-stream_loop', '-1',
                '-re',
                '-i', $recordingFile,
            ],
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

    /**
     * Launch an ffmpeg process in the background.
     *
     * - Exports a safe PATH so ffmpeg is found regardless of how PHP was invoked
     * - Writes stdout+stderr to log file
     * - Writes PID to pidFile
     * - Waits up to 3 seconds to confirm the process is still alive
     * - On failure, reads the log file and throws a descriptive exception
     *
     * @throws \RuntimeException with ffmpeg stderr output on failure
     */
    public function startProcess(array $command, string $pidFile, string $logFile): int
    {
        foreach ([dirname($logFile), dirname($pidFile)] as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }

        // Rotate log: keep last 200 KB
        if (file_exists($logFile) && filesize($logFile) > 204_800) {
            rename($logFile, $logFile . '.1');
        }

        // Build the shell command with explicit PATH so ffmpeg is found
        $escaped = implode(' ', array_map('escapeshellarg', $command));
        $path    = '/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin';

        $shell = "export PATH={$path}:\$PATH; nohup {$escaped} >> "
               . escapeshellarg($logFile) . " 2>&1 & echo \$!";

        $pid = (int) trim((string) shell_exec($shell));

        if ($pid <= 0) {
            $error = $this->readLogTail($logFile, 30);
            throw new \RuntimeException(
                "ffmpeg process did not start (PID=0). Log:\n{$error}"
            );
        }

        // Write PID immediately
        file_put_contents($pidFile, $pid);

        // Wait up to 3 seconds for ffmpeg to either stabilise or die
        $alive = false;
        for ($i = 0; $i < 6; $i++) {
            usleep(500_000); // 500 ms
            if (!$this->isRunning($pid)) break;
            $alive = true;
            // After 1.5 s consider it healthy
            if ($i >= 2) break;
        }

        if (!$alive) {
            $this->clearPid($pidFile);
            $error = $this->readLogTail($logFile, 40);
            throw new \RuntimeException(
                "ffmpeg exited immediately. Log:\n{$error}"
            );
        }

        return $pid;
    }

    public function stopProcess(int $pid): void
    {
        if ($pid <= 0) return;

        exec("kill -TERM {$pid} 2>/dev/null");

        $waited = 0;
        while ($this->isRunning($pid) && $waited < 60) {
            usleep(100_000);
            $waited++;
        }

        if ($this->isRunning($pid)) {
            exec("kill -KILL {$pid} 2>/dev/null");
        }
    }

    public function isRunning(int $pid): bool
    {
        if ($pid <= 0) return false;
        // /proc is fastest check on Linux
        if (is_dir("/proc/{$pid}")) return true;
        exec("ps -p {$pid} -o pid= 2>/dev/null", $out, $code);
        return $code === 0 && !empty(trim(implode('', $out)));
    }

    // ===================================================================
    //  DIAGNOSTICS — returns a human-readable report for the admin UI
    // ===================================================================

    /**
     * Run the exact ingest command for 5 seconds and capture all output.
     * Returns the stderr/stdout so the admin can see exactly what went wrong.
     */
    public function diagnoseIngest(Channel $channel): array
    {
        $dvrDir = $channel->dvr_directory;
        if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

        // Build a test command that runs for 5 s then exits
        $cmd = $this->buildIngestCommand($channel);

        // Inject -t 5 before the output file
        array_splice($cmd, -1, 0, ['-t', '5']);

        // Also add verbose logging for the test
        $cmd[3] = 'info'; // replace 'warning' with 'info'

        $proc = new Process($cmd);
        $proc->setTimeout(15);
        $proc->run();

        return [
            'command'   => implode(' ', $cmd),
            'exit_code' => $proc->getExitCode(),
            'stdout'    => $proc->getOutput(),
            'stderr'    => $proc->getErrorOutput(),
            'success'   => $proc->isSuccessful(),
        ];
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
            Log::debug("Health check [{$channel->name}]: {$e->getMessage()}");
            return false;
        }
    }

    public function probeStream(Channel $channel): array
    {
        $args = [
            $this->ffprobeBin,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format', '-show_streams',
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
        return (int) trim((string) file_get_contents($pidFile));
    }

    public function clearPid(string $pidFile): void { @unlink($pidFile); }

    public function readLogTail(string $logFile, int $lines = 50): string
    {
        if (!file_exists($logFile)) return "(log file not found: {$logFile})";
        $out = [];
        exec("tail -n {$lines} " . escapeshellarg($logFile) . " 2>/dev/null", $out);
        return implode("\n", $out);
    }

    // ===================================================================
    //  INTERNAL — INPUT FLAGS
    // ===================================================================

    protected function inputFlags(Channel $channel): array
    {
        $probesize = in_array($channel->source_type, ['udp', 'mpegts']) ? '5000000' : '2000000';
        $analyze   = in_array($channel->source_type, ['udp', 'mpegts']) ? '3000000' : '2000000';

        return match ($channel->source_type) {
            'udp', 'mpegts' => [
                '-fflags',          '+genpts+discardcorrupt',
                '-probesize',       $probesize,
                '-analyzeduration', $analyze,
                '-timeout',         '10000000',
                '-i',               $channel->source_url,
            ],
            'hls' => [
                '-re',
                '-fflags',             '+genpts',
                '-probesize',          $probesize,
                '-analyzeduration',    $analyze,
                '-allowed_extensions', 'ALL',
                '-timeout',            '15000000',
                '-i',                  $channel->source_url,
            ],
            'srt' => [
                '-fflags',          '+genpts+discardcorrupt',
                '-probesize',       $probesize,
                '-analyzeduration', $analyze,
                '-i',               'srt://' . $this->parseSrtUrl($channel->source_url)
                                    . '?timeout=8000000&latency='
                                    . (config('skymedia.srt_latency', 200) * 1000),
            ],
            // rtmp and any unknown type
            default => [
                '-fflags',          '+genpts+discardcorrupt',
                '-probesize',       $probesize,
                '-analyzeduration', $analyze,
                '-timeout',         '10000000',
                '-i',               $channel->source_url,
            ],
        };
    }

    // ===================================================================
    //  INTERNAL — ENCODING FLAGS
    // ===================================================================

    protected function videoEncodeFlags(Channel $channel): array
    {
        $codec = $channel->push_video_codec ?? 'copy';

        if ($codec === 'copy') return ['-c:v', 'copy'];

        $ffCodec = match ($codec) {
            'h264' => 'libx264',
            'h265' => 'libx265',
            'vp8'  => 'libvpx',
            'vp9'  => 'libvpx-vp9',
            default => 'libx264',
        };

        $flags = ['-c:v', $ffCodec];

        if ($channel->push_video_bitrate) {
            $kbps  = (int) $channel->push_video_bitrate;
            $flags = array_merge($flags, [
                '-b:v',     "{$kbps}k",
                '-maxrate', (int) ($kbps * 1.2) . 'k',
                '-bufsize', ($kbps * 2) . 'k',
            ]);
        }

        if (!empty($channel->push_resolution)) {
            $flags = array_merge($flags, ['-vf', "scale={$channel->push_resolution}"]);
        }

        if ($channel->push_framerate) {
            $flags = array_merge($flags, ['-r', (string) $channel->push_framerate]);
        }

        if (in_array($codec, ['h264', 'h265'])) {
            $flags = array_merge($flags, [
                '-preset',       'veryfast',
                '-tune',         'zerolatency',
                '-g',            (string) (($channel->push_framerate ?? 25) * 2),
                '-keyint_min',   (string) ($channel->push_framerate ?? 25),
                '-sc_threshold', '0',
            ]);
        }

        return $flags;
    }

    protected function audioEncodeFlags(Channel $channel): array
    {
        $codec = $channel->push_audio_codec ?? 'aac';

        if ($codec === 'copy') return ['-c:a', 'copy'];

        $ffCodec = match ($codec) {
            'aac'   => 'aac',
            'mp3'   => 'libmp3lame',
            'opus'  => 'libopus',
            'ac3'   => 'ac3',
            default => 'aac',
        };

        return [
            '-c:a', $ffCodec,
            '-b:a', ((int) ($channel->push_audio_bitrate ?? 128)) . 'k',
            '-ar',  (string) (int) ($channel->push_audio_samplerate ?? 48000),
            '-ac',  (string) (int) ($channel->push_audio_channels ?? 2),
        ];
    }

    // ===================================================================
    //  INTERNAL — PUSH URL
    // ===================================================================

    protected function pushUrl(Channel $channel): string
    {
        if ($channel->push_protocol === 'srt') {
            $latency = config('skymedia.srt_latency', 200) * 1000;
            return 'srt://' . $this->parseSrtUrl($channel->push_target)
                   . "?latency={$latency}&mode=caller";
        }
        return $channel->push_target;
    }

    protected function parseSrtUrl(string $url): string
    {
        return preg_replace('#^srt://#', '', $url);
    }
}
