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

    // ---------------------------------------------------------------
    // Combined: source → DVR segments + push output (single process)
    // This is the primary live command — uses tee muxer so one ffmpeg
    // decodes the source once and writes both outputs simultaneously.
    // ---------------------------------------------------------------

    public function buildLiveCommand(Channel $channel): array
    {
        $dvrDir         = $channel->dvr_directory;
        if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

        $segmentPattern = "{$dvrDir}/seg_%05d.ts";
        $wrapCount      = (int) ceil($channel->dvr_duration / $channel->segment_duration) + 20;

        // DVR tee output options
        $dvrOpts = http_build_query([
            'f'                    => 'segment',
            'segment_time'         => $channel->segment_duration,
            'segment_format'       => 'mpegts',
            'segment_wrap'         => max($wrapCount, 100),
            'reset_timestamps'     => 1,
            'strftime'             => 0,
            'individual_header_trailer' => 0,
            'break_non_keyframes'  => 0,
        ], '', ':');

        // Push tee output options
        $pushFmt  = $channel->push_protocol === 'srt' ? 'mpegts' : 'flv';
        $pushExtra = $channel->push_protocol === 'rtmp' ? ':flvflags=no_duration_filesize' : '';
        $pushOpts = "f={$pushFmt}{$pushExtra}";
        $pushUrl  = $this->pushUrl($channel);

        $teeMap = "[{$dvrOpts}]{$segmentPattern}|[{$pushOpts}]{$pushUrl}";

        return array_merge(
            [$this->ffmpegBin, '-y', '-loglevel', 'warning'],
            $this->inputFlags($channel),
            ['-c:v', 'copy'],
            $this->audioFlags(),
            ['-f', 'tee', '-map', '0:v?', '-map', '0:a?', $teeMap]
        );
    }

    // ---------------------------------------------------------------
    // DVR recorder only: source → segmented .ts files (no push)
    // Used when push is disabled or push is handled separately.
    // ---------------------------------------------------------------

    public function buildDvrRecordCommand(Channel $channel): array
    {
        $dvrDir = $channel->dvr_directory;
        if (!is_dir($dvrDir)) {
            mkdir($dvrDir, 0755, true);
        }

        $segmentPattern = "{$dvrDir}/seg_%05d.ts";
        $wrapCount      = (int) ceil($channel->dvr_duration / $channel->segment_duration) + 20;

        return array_merge(
            [$this->ffmpegBin, '-y', '-loglevel', 'warning'],
            $this->inputFlags($channel),
            ['-c:v', 'copy'],
            $this->audioFlags(),
            [
                '-f', 'segment',
                '-segment_time', (string) $channel->segment_duration,
                '-segment_format', 'mpegts',
                '-segment_wrap', (string) max($wrapCount, 100),
                '-reset_timestamps', '1',
                '-strftime', '0',
                '-individual_header_trailer', '0',
                '-break_non_keyframes', '0',
                $segmentPattern,
            ]
        );
    }

    // ---------------------------------------------------------------
    // Push only: source → RTMP/SRT directly (no DVR)
    // ---------------------------------------------------------------

    public function buildPushCommand(Channel $channel): array
    {
        return array_merge(
            [$this->ffmpegBin, '-y', '-loglevel', 'warning'],
            $this->inputFlags($channel),
            ['-c:v', 'copy'],
            $this->audioFlags(),
            ['-f', $channel->push_protocol === 'srt' ? 'mpegts' : 'flv'],
            $channel->push_protocol === 'rtmp'
                ? ['-flvflags', 'no_duration_filesize']
                : [],
            [$this->pushUrl($channel)]
        );
    }

    // ---------------------------------------------------------------
    // DVR playback: concat.txt → RTMP/SRT (looping)
    // ---------------------------------------------------------------

    public function buildDvrPlaybackCommand(Channel $channel): array
    {
        $concatFile = $channel->dvr_directory . '/concat.txt';

        return array_merge(
            [$this->ffmpegBin, '-y', '-loglevel', 'warning'],
            ['-stream_loop', '-1', '-safe', '0', '-f', 'concat', '-i', $concatFile],
            ['-c:v', 'copy'],
            $this->audioFlags(),
            ['-f', $channel->push_protocol === 'srt' ? 'mpegts' : 'flv'],
            $channel->push_protocol === 'srt'
                ? []
                : ['-flvflags', 'no_duration_filesize'],
            [$this->pushUrl($channel)]
        );
    }

    // ===================================================================
    //  PROCESS MANAGEMENT
    // ===================================================================

    public function startProcess(array $command, string $pidFile, string $logFile): int
    {
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $cmd = implode(' ', array_map('escapeshellarg', $command));
        $shell = "nohup {$cmd} >> " . escapeshellarg($logFile) . " 2>&1 & echo $!";

        $pid = (int) trim(shell_exec($shell));

        if ($pid > 0) {
            file_put_contents($pidFile, $pid);
            usleep(200000);
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

        $sigterm = defined('SIGTERM') ? SIGTERM : 15;
        $sigkill = defined('SIGKILL') ? SIGKILL : 9;

        if (function_exists('posix_kill')) {
            posix_kill($pid, $sigterm);
        } else {
            exec("kill {$pid} 2>/dev/null");
        }

        $waited = 0;
        while ($this->isRunning($pid) && $waited < 50) {
            usleep(100_000);
            $waited++;
        }

        if ($this->isRunning($pid)) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, $sigkill);
            } else {
                exec("kill -9 {$pid} 2>/dev/null");
            }
        }
    }

    public function isRunning(int $pid): bool
    {
        if ($pid <= 0) return false;

        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        if (is_dir("/proc/{$pid}")) {
            return true;
        }

        $output = [];
        exec("ps -p {$pid} 2>/dev/null", $output, $exitCode);
        return $exitCode === 0;
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
                $args = array_merge($args, [
                    '-analyzeduration', '2000000',
                    '-probesize', '3000000',
                ]);
            }

            if ($channel->source_type === 'hls') {
                $args[] = '-allowed_extensions';
                $args[] = 'ALL';
            }

            $args[] = $channel->source_url;

            $proc = new Process($args);
            $proc->setTimeout(10);
            $proc->run();

            if (!$proc->isSuccessful()) return false;

            $data = json_decode($proc->getOutput(), true);
            $hasStreams = !empty($data['streams']);

            if (!$hasStreams && $channel->source_type === 'hls') {
                $httpCode = $this->checkHttpUrl($channel->source_url);
                return $httpCode >= 200 && $httpCode < 400;
            }

            return $hasStreams;
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
            $args[] = '-allowed_extensions';
            $args[] = 'ALL';
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
    //  PID / FILE HELPERS
    // ===================================================================

    public function pidFile(Channel $channel, string $type = 'live'): string
    {
        $dir = storage_path("app/pids");
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return "{$dir}/{$type}_{$channel->id}.pid";
    }

    public function logFile(Channel $channel, string $type = 'live'): string
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

    public function readLogTail(string $logFile, int $lines = 20): string
    {
        if (!file_exists($logFile)) return '';
        $output = '';
        exec("tail -{$lines} " . escapeshellarg($logFile) . " 2>/dev/null", $output);
        return implode("\n", $output);
    }

    // ===================================================================
    //  INTERNAL
    // ===================================================================

    protected function inputFlags(Channel $channel): array
    {
        $probesize = in_array($channel->source_type, ['udp', 'mpegts']) ? '5000000' : '1000000';
        $analyze   = in_array($channel->source_type, ['udp', 'mpegts']) ? '3000000' : '1000000';

        return match ($channel->source_type) {
            'udp', 'mpegts' => [
                '-fflags', '+genpts+discardcorrupt',
                '-probesize', $probesize,
                '-analyzeduration', $analyze,
                '-timeout', '10000000',
                '-i', $channel->source_url,
            ],
            'hls' => [
                '-re',
                '-fflags', '+genpts',
                '-probesize', $probesize,
                '-analyzeduration', $analyze,
                '-timeout', '15000000',
                '-i', $channel->source_url,
            ],
            'srt' => [
                '-fflags', '+genpts+discardcorrupt',
                '-probesize', $probesize,
                '-analyzeduration', $analyze,
                '-i', "srt://{$this->parseSrtUrl($channel->source_url)}?timeout=8000000&latency=" . config('skymedia.srt_latency', 200) . '000',
            ],
            default => [
                '-fflags', '+genpts+discardcorrupt',
                '-probesize', $probesize,
                '-analyzeduration', $analyze,
                '-timeout', '10000000',
                '-i', $channel->source_url,
            ],
        };
    }

    protected function audioFlags(): array
    {
        return ['-c:a', 'aac', '-ar', '44100', '-ac', '2', '-b:a', '128k'];
    }

    protected function pushUrl(Channel $channel): string
    {
        $target = $channel->push_target;

        if ($channel->push_protocol === 'srt') {
            $latency = config('skymedia.srt_latency', 200) * 1000;
            $host    = $this->parseSrtUrl($target);
            return "srt://{$host}?latency={$latency}&mode=caller";
        }

        return $target;
    }

    protected function parseSrtUrl(string $url): string
    {
        return preg_replace('#^srt://#', '', $url);
    }

    protected function checkHttpUrl(string $url): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY       => true,
            CURLOPT_TIMEOUT      => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT    => 'SkyMedia/1.0',
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }
}
