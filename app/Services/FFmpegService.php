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
     * INGEST: source → HLS segments on disk (DVR buffer)
     *
     * Rules:
     * - Always stream copy on ingest (no re-encoding, minimal CPU)
     * - hls_list_size 0  → ffmpeg never trims the playlist
     * - delete_segments  → ffmpeg removes old .ts but we also enforce via DVRService
     * - omit_endlist     → playlist stays "live" (no EXT-X-ENDLIST)
     * - allowed_extensions ONLY added for genuine HLS sources
     */
    public function buildIngestCommand(Channel $channel): array
    {
        $dvrDir     = $channel->dvr_directory;
        $m3u8       = "{$dvrDir}/live.m3u8";
        $segPattern = "{$dvrDir}/seg_%05d.ts";

        return array_merge(
            [
                $this->ffmpegBin,
                '-y',
                '-loglevel', 'warning',
                '-stats',
            ],
            $this->inputFlags($channel),
            [
                '-c:v', 'copy',
                '-c:a', 'copy',
                '-f',                    'hls',
                '-hls_time',             (string) max(1, (int) $channel->segment_duration),
                '-hls_list_size',        '0',
                '-hls_flags',            'delete_segments+omit_endlist',
                '-hls_delete_threshold', '1',
                '-hls_segment_type',     'mpegts',
                '-hls_segment_filename', $segPattern,
                '-hls_allow_cache',      '0',
                '-start_number',         '0',
                $m3u8,
            ]
        );
    }

    /**
     * PUSH: reads live.m3u8 → encode → RTMP/SRT
     */
    public function buildPushCommand(Channel $channel, string $playlistPath): array
    {
        return array_merge(
            [
                $this->ffmpegBin, '-y', '-loglevel', 'warning', '-stats',
                '-fflags',             '+genpts+igndts+discardcorrupt',
                '-live_start_index',   '-1',
                '-allowed_extensions', 'ALL',
                '-protocol_whitelist', 'file,crypto,data,http,https,tcp,tls',
                '-timeout',            '10000000',
                '-i',                  $playlistPath,
            ],
            $this->videoEncodeFlags($channel),
            $this->audioEncodeFlags($channel),
            ['-f', $channel->push_protocol === 'srt' ? 'mpegts' : 'flv'],
            $channel->push_protocol === 'rtmp' ? ['-flvflags', 'no_duration_filesize'] : [],
            [$this->pushUrl($channel)]
        );
    }

    /**
     * DVR LOOP: concat.txt → encode → RTMP/SRT (looping)
     * Kept for manual operator use via DVR playback.
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

    // ===================================================================
    //  PROCESS MANAGEMENT
    // ===================================================================

    /**
     * Launch an ffmpeg process in the background.
     * Throws \RuntimeException with full ffmpeg stderr on failure.
     */
    public function startProcess(array $command, string $pidFile, string $logFile, int $stabiliseSeconds = 3): int
    {
        foreach ([dirname($logFile), dirname($pidFile)] as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }

        // Rotate log when it exceeds 200 KB
        if (file_exists($logFile) && filesize($logFile) > 204_800) {
            rename($logFile, $logFile . '.1');
        }

        $escaped = implode(' ', array_map('escapeshellarg', $command));
        $path    = '/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin';
        $shell   = "export PATH={$path}:\$PATH; nohup {$escaped} >> "
                 . escapeshellarg($logFile) . " 2>&1 & echo \$!";

        $pid = (int) trim((string) shell_exec($shell));

        if ($pid <= 0) {
            $error = $this->readLogTail($logFile, 30);
            throw new \RuntimeException("ffmpeg did not start (PID=0).\n{$error}");
        }

        file_put_contents($pidFile, $pid);

        // Wait up to $stabiliseSeconds for process to stabilise
        $checks   = $stabiliseSeconds * 2; // 500ms per check
        $minAlive = max(2, (int) ($stabiliseSeconds * 0.6)); // must survive 60% of window
        $alive    = false;
        for ($i = 0; $i < $checks; $i++) {
            usleep(500_000);
            if (!$this->isRunning($pid)) break;
            if ($i >= $minAlive) { $alive = true; break; }
        }

        if (!$alive) {
            $this->clearPid($pidFile);
            $error = $this->readLogTail($logFile, 40);
            throw new \RuntimeException("ffmpeg exited immediately.\n{$error}");
        }

        return $pid;
    }

    public function stopProcess(int $pid): void
    {
        if ($pid <= 0) return;
        exec("kill -TERM {$pid} 2>/dev/null");
        $w = 0;
        while ($this->isRunning($pid) && $w++ < 60) usleep(100_000);
        if ($this->isRunning($pid)) exec("kill -KILL {$pid} 2>/dev/null");
    }

    public function isRunning(int $pid): bool
    {
        if ($pid <= 0) return false;
        if (is_dir("/proc/{$pid}")) return true;
        exec("ps -p {$pid} -o pid= 2>/dev/null", $out, $code);
        return $code === 0 && !empty(trim(implode('', $out)));
    }

    // ===================================================================
    //  DIAGNOSTICS
    // ===================================================================

    /**
     * Run ingest for 5 s and capture all output for the admin UI.
     */
    public function diagnoseIngest(Channel $channel): array
    {
        $dvrDir = $channel->dvr_directory;
        if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

        $cmd = $this->buildIngestCommand($channel);
        // Resolve full binary path for Symfony Process (different PATH from shell_exec)
        $resolved = trim((string) shell_exec('which ' . escapeshellarg($this->ffmpegBin) . ' 2>/dev/null'))
                 ?: trim((string) shell_exec('command -v ' . escapeshellarg($this->ffmpegBin) . ' 2>/dev/null'));
        if ($resolved) {
            $cmd[0] = $resolved;
        }
        foreach ($cmd as &$v) { if ($v === 'warning') { $v = 'info'; break; } }
        unset($v);
        array_splice($cmd, -1, 0, ['-t', '5']);

        $proc = new Process($cmd);
        $proc->setTimeout(20);
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

            // Add source-type specific flags
            $this->addProbeInputFlags($args, $channel);

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

        $this->addProbeInputFlags($args, $channel);
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
        if (!file_exists($logFile)) return "(log not found: {$logFile})";
        $out = [];
        exec("tail -n {$lines} " . escapeshellarg($logFile) . " 2>/dev/null", $out);
        return implode("\n", $out);
    }

    // ===================================================================
    //  INTERNAL — INPUT FLAGS
    // ===================================================================

    /**
     * Returns the correct ffmpeg input flags for the channel source type.
     *
     * KEY RULES:
     * - allowed_extensions is HLS-ONLY (causes "Option not found" on all other demuxers)
     * - HTTP MPEG-TS (common IPTV format): URL is http/https but content is raw MPEG-TS
     *   detected when source_type is mpegts/udp/rtmp or URL has no .m3u8/.m3u extension
     * - -re (read at native frame rate) only for HLS pull — live UDP/SRT/RTMP push at their own rate
     */
    protected function inputFlags(Channel $channel): array
    {
        $url       = $channel->source_url;
        $type      = $channel->source_type;
        $probesize = '5000000';
        $analyze   = '3000000';

        // Detect HTTP MPEG-TS: http(s) URL but NOT an HLS playlist
        $isHttpMpegts = $this->isHttpMpegts($url, $type);

        switch (true) {

            // HLS: http/https URLs ending in .m3u8/.m3u OR explicitly set to hls
            case ($type === 'hls' && !$isHttpMpegts):
                return [
                    '-re',
                    '-fflags',             '+genpts',
                    '-probesize',          $probesize,
                    '-analyzeduration',    $analyze,
                    '-allowed_extensions', 'ALL',   // HLS ONLY
                    '-protocol_whitelist', 'file,crypto,data,http,https,tcp,tls',
                    '-timeout',            '15000000',
                    '-i',                  $url,
                ];

            // HTTP MPEG-TS (IPTV streams: http://host/user/pass/channel_id)
            case $isHttpMpegts:
                return [
                    '-fflags',          '+genpts+discardcorrupt',
                    '-probesize',       $probesize,
                    '-analyzeduration', $analyze,
                    '-timeout',         '10000000',
                    '-i',               $url,
                ];

            // UDP multicast / MPEG-TS over UDP
            case ($type === 'udp' || $type === 'mpegts'):
                return [
                    '-fflags',          '+genpts+discardcorrupt',
                    '-probesize',       $probesize,
                    '-analyzeduration', $analyze,
                    '-timeout',         '10000000',
                    '-i',               $url,
                ];

            // SRT
            case ($type === 'srt'):
                $latency = config('skymedia.srt_latency', 200) * 1000;
                $srtUrl  = 'srt://' . $this->parseSrtUrl($url)
                         . "?timeout=8000000&latency={$latency}";
                return [
                    '-fflags',          '+genpts+discardcorrupt',
                    '-probesize',       $probesize,
                    '-analyzeduration', $analyze,
                    '-i',               $srtUrl,
                ];

            // RTMP and anything else
            default:
                return [
                    '-fflags',          '+genpts+discardcorrupt',
                    '-probesize',       $probesize,
                    '-analyzeduration', $analyze,
                    '-timeout',         '10000000',
                    '-i',               $url,
                ];
        }
    }

    /**
     * Detect HTTP MPEG-TS streams:
     * - http/https URL that does NOT end in .m3u8 or .m3u
     * - OR source_type is mpegts/udp but URL starts with http
     */
    protected function isHttpMpegts(string $url, string $type): bool
    {
        $isHttp = str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
        if (!$isHttp) return false;

        // Explicit non-HLS types over HTTP
        if (in_array($type, ['mpegts', 'udp', 'rtmp'])) return true;

        // HLS type but URL has no playlist extension = IPTV MPEG-TS
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $hasPlaylistExt = preg_match('/\.(m3u8?|ts)([\?#]|$)/i', $path);
        if ($type === 'hls' && !$hasPlaylistExt) return true;

        return false;
    }

    /**
     * Add appropriate ffprobe input flags without allowed_extensions on non-HLS.
     */
    protected function addProbeInputFlags(array &$args, Channel $channel): void
    {
        $type = $channel->source_type;
        $url  = $channel->source_url;

        if ($type === 'hls' && !$this->isHttpMpegts($url, $type)) {
            array_push($args, '-allowed_extensions', 'ALL');
        }

        if (in_array($type, ['udp', 'mpegts', 'srt'])) {
            array_push($args, '-analyzeduration', '2000000', '-probesize', '3000000');
        }
    }

    // ===================================================================
    //  INTERNAL — ENCODING FLAGS
    // ===================================================================

    protected function videoEncodeFlags(Channel $channel): array
    {
        $codec = $channel->push_video_codec ?? 'copy';
        if ($codec === 'copy') return ['-c:v', 'copy'];

        $ffCodec = match ($codec) {
            'h264' => 'libx264', 'h265' => 'libx265',
            'vp8'  => 'libvpx',  'vp9'  => 'libvpx-vp9',
            default => 'libx264',
        };

        $flags = ['-c:v', $ffCodec];

        if ($channel->push_video_bitrate) {
            $kbps  = (int) $channel->push_video_bitrate;
            $flags = array_merge($flags, [
                '-b:v', "{$kbps}k",
                '-maxrate', (int)($kbps * 1.2) . 'k',
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
            $fps   = $channel->push_framerate ?? 25;
            $flags = array_merge($flags, [
                '-preset', 'veryfast', '-tune', 'zerolatency',
                '-g', (string)($fps * 2), '-keyint_min', (string)$fps,
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
            'aac'  => 'aac', 'mp3' => 'libmp3lame',
            'opus' => 'libopus', 'ac3' => 'ac3',
            default => 'aac',
        };

        return [
            '-c:a', $ffCodec,
            '-b:a', ((int)($channel->push_audio_bitrate ?? 128)) . 'k',
            '-ar',  (string)(int)($channel->push_audio_samplerate ?? 48000),
            '-ac',  (string)(int)($channel->push_audio_channels ?? 2),
        ];
    }

    // ===================================================================
    //  INTERNAL — PUSH URL
    // ===================================================================

    protected function pushUrl(Channel $channel): string
    {
        if ($channel->push_protocol === 'srt') {
            $latency = config('skymedia.srt_latency', 200) * 1000;
            $base    = $this->parseSrtUrl($channel->push_target);
            $query   = "latency={$latency}&mode=caller";
            if ($channel->push_username) {
                $query .= '&username=' . urlencode($channel->push_username);
            }
            if ($channel->push_password) {
                $query .= '&passphrase=' . urlencode($channel->push_password);
            }
            return "srt://{$base}?{$query}";
        }

        // RTMP — credentials embedded as rtmp://user:pass@host/app/key
        $target = $channel->push_target;
        if ($channel->push_username || $channel->push_password) {
            $user = urlencode($channel->push_username ?? '');
            $pass = urlencode($channel->push_password ?? '');
            // Insert credentials after rtmp(s)://
            $target = preg_replace(
                '#^(rtmps?://)#',
                "$1{$user}:{$pass}@",
                $target
            );
        }
        return $target;
    }

    protected function parseSrtUrl(string $url): string
    {
        return preg_replace('#^srt://#', '', $url);
    }
}
