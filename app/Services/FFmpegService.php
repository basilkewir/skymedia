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

    // ---------------------------------------------------------------
    // Input flags per source type
    // ---------------------------------------------------------------

    protected function inputFlags(Channel $channel): array
    {
        return match ($channel->source_type) {
            'udp', 'mpegts' => [
                '-fflags', '+genpts+discardcorrupt',
                '-timeout', '5000000',        // 5 s connect timeout (µs)
                '-i', $channel->source_url,
            ],
            'hls' => [
                '-re',
                '-fflags', '+genpts',
                '-timeout', '10000000',
                '-i', $channel->source_url,
            ],
            'srt' => [
                '-fflags', '+genpts+discardcorrupt',
                '-i', "srt://{$this->parseSrtUrl($channel->source_url)}?timeout=5000000&latency=" . config('skymedia.srt_latency', 200) . '000',
            ],
            default => [               // rtmp
                '-fflags', '+genpts+discardcorrupt',
                '-timeout', '5000000',
                '-i', $channel->source_url,
            ],
        };
    }

    private function parseSrtUrl(string $url): string
    {
        // Strip srt:// prefix if present so we don't double it
        return preg_replace('#^srt://#', '', $url);
    }

    // ---------------------------------------------------------------
    // Push output flags
    // ---------------------------------------------------------------

    protected function pushOutputFlags(Channel $channel): array
    {
        $target = $channel->push_target;

        if ($channel->push_protocol === 'srt') {
            $latency = config('skymedia.srt_latency', 200) * 1000;
            $srtHost = $this->parseSrtUrl($target);
            return [
                '-c:v', 'copy',
                '-c:a', 'aac', '-ar', '44100', '-ac', '2',
                '-f', 'mpegts',
                "srt://{$srtHost}?latency={$latency}&mode=caller",
            ];
        }

        // RTMP
        return [
            '-c:v', 'copy',
            '-c:a', 'aac', '-ar', '44100', '-ac', '2',
            '-f', 'flv',
            '-flvflags', 'no_duration_filesize',
            $target,
        ];
    }

    // ---------------------------------------------------------------
    // DVR segment output flags
    // ---------------------------------------------------------------

    protected function dvrOutputFlags(Channel $channel): array
    {
        $dvrDir          = $channel->dvr_directory;
        $segmentPattern  = "{$dvrDir}/seg_%05d.ts";
        $segmentList     = "{$dvrDir}/index.m3u8";
        $maxSegs         = (int) ceil($channel->dvr_duration / $channel->segment_duration);

        return [
            '-c:v', 'copy',
            '-c:a', 'aac', '-ar', '44100', '-ac', '2',
            '-f', 'segment',
            '-segment_time', (string) $channel->segment_duration,
            '-segment_format', 'mpegts',
            '-segment_list', $segmentList,
            '-segment_list_size', (string) $maxSegs,
            '-segment_list_type', 'm3u8',
            '-segment_wrap', (string) ($maxSegs + 10),   // rotate filenames
            '-reset_timestamps', '1',
            '-strftime', '0',
            $segmentPattern,
        ];
    }

    // ---------------------------------------------------------------
    // Build the live ingest command (DVR + push simultaneously)
    // ---------------------------------------------------------------

    public function buildLiveCommand(Channel $channel): array
    {
        $dvrDir = $channel->dvr_directory;
        if (!is_dir($dvrDir)) {
            mkdir($dvrDir, 0755, true);
        }

        $input = $this->inputFlags($channel);

        // tee muxer: write DVR segments AND push at the same time
        $dvrFlags  = $this->dvrOutputFlagsTee($channel);
        $pushFlags = $this->pushOutputFlagsTee($channel);

        return array_merge(
            [$this->ffmpegBin, '-y'],
            $input,
            [
                '-c:v', 'copy',
                '-c:a', 'aac', '-ar', '44100', '-ac', '2',
                '-f', 'tee',
                '-map', '0:v?', '-map', '0:a?',
                "{$dvrFlags}|{$pushFlags}",
            ]
        );
    }

    private function dvrOutputFlagsTee(Channel $channel): string
    {
        $dvrDir         = $channel->dvr_directory;
        $segmentPattern = "{$dvrDir}/seg_%05d.ts";
        $segmentList    = "{$dvrDir}/index.m3u8";
        $maxSegs        = (int) ceil($channel->dvr_duration / $channel->segment_duration);

        $opts = http_build_query([
            'segment_time'        => $channel->segment_duration,
            'segment_format'      => 'mpegts',
            'segment_list'        => $segmentList,
            'segment_list_size'   => $maxSegs,
            'segment_list_type'   => 'm3u8',
            'segment_wrap'        => $maxSegs + 10,
            'reset_timestamps'    => 1,
            'strftime'            => 0,
            'f'                   => 'segment',
        ], '', ':');

        return "[{$opts}]{$segmentPattern}";
    }

    private function pushOutputFlagsTee(Channel $channel): string
    {
        $target = $channel->push_target;

        if ($channel->push_protocol === 'srt') {
            $latency = config('skymedia.srt_latency', 200) * 1000;
            $host    = $this->parseSrtUrl($target);
            $url     = "srt://{$host}?latency={$latency}&mode=caller";
            return "[f=mpegts]{$url}";
        }

        return "[f=flv:flvflags=no_duration_filesize]{$target}";
    }

    // ---------------------------------------------------------------
    // DVR playback command (looping concat -> push)
    // ---------------------------------------------------------------

    public function buildDvrPlaybackCommand(Channel $channel): array
    {
        $concatFile = $channel->dvr_directory . '/concat.txt';

        if ($channel->push_protocol === 'srt') {
            $latency = config('skymedia.srt_latency', 200) * 1000;
            $host    = $this->parseSrtUrl($channel->push_target);
            $output  = ["srt://{$host}?latency={$latency}&mode=caller"];
            $fmt     = ['-f', 'mpegts'];
        } else {
            $output = [$channel->push_target];
            $fmt    = ['-f', 'flv', '-flvflags', 'no_duration_filesize'];
        }

        return array_merge(
            [$this->ffmpegBin, '-y'],
            ['-re', '-stream_loop', '-1', '-safe', '0', '-f', 'concat', '-i', $concatFile],
            ['-c:v', 'copy', '-c:a', 'aac', '-ar', '44100', '-ac', '2'],
            $fmt,
            $output
        );
    }

    // ---------------------------------------------------------------
    // Build/update concat.txt from available segments (ordered)
    // ---------------------------------------------------------------

    public function buildConcatFile(Channel $channel): bool
    {
        $dvrDir  = $channel->dvr_directory;
        $files   = glob("{$dvrDir}/seg_*.ts");

        if (empty($files)) {
            return false;
        }

        sort($files);
        $lines = array_map(fn($f) => "file '{$f}'", $files);
        file_put_contents("{$dvrDir}/concat.txt", implode("\n", $lines));
        return true;
    }

    // ---------------------------------------------------------------
    // Process management
    // ---------------------------------------------------------------

    public function startProcess(array $command, string $pidFile, string $logFile): int
    {
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Build shell command that writes PID and redirects logs
        $cmd = implode(' ', array_map('escapeshellarg', $command));
        $shell = "{$cmd} >> " . escapeshellarg($logFile) . " 2>&1 & echo $!";

        $pid = (int) shell_exec($shell);

        if ($pid > 0) {
            file_put_contents($pidFile, $pid);
        }

        return $pid;
    }

    public function stopProcess(int $pid): void
    {
        if ($pid <= 0) return;

        // SIGTERM first, then SIGKILL after 5 s
        posix_kill($pid, SIGTERM);
        $waited = 0;
        while ($this->isRunning($pid) && $waited < 50) {
            usleep(100_000);
            $waited++;
        }
        if ($this->isRunning($pid)) {
            posix_kill($pid, SIGKILL);
        }
    }

    public function isRunning(int $pid): bool
    {
        if ($pid <= 0) return false;
        return file_exists("/proc/{$pid}");
    }

    // ---------------------------------------------------------------
    // Stream health check via ffprobe
    // ---------------------------------------------------------------

    public function checkSourceHealth(Channel $channel): bool
    {
        try {
            $args = [
                $this->ffprobeBin,
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_streams',
                '-timeout', '5000000',
            ];

            if (in_array($channel->source_type, ['udp', 'mpegts', 'srt'])) {
                $args[] = '-analyzeduration';
                $args[] = '2000000';
            }

            $args[] = $channel->source_url;

            $proc = new Process($args);
            $proc->setTimeout(8);
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
        $proc = new Process([
            $this->ffprobeBin,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            '-timeout', '8000000',
            $channel->source_url,
        ]);
        $proc->setTimeout(12);
        $proc->run();

        if (!$proc->isSuccessful()) {
            return ['error' => $proc->getErrorOutput()];
        }

        return json_decode($proc->getOutput(), true) ?? [];
    }

    // ---------------------------------------------------------------
    // DVR cleanup — enforce rolling window
    // ---------------------------------------------------------------

    public function enforceWindow(Channel $channel): void
    {
        $dvrDir  = $channel->dvr_directory;
        if (!is_dir($dvrDir)) return;

        $maxSegs = (int) ceil($channel->dvr_duration / $channel->segment_duration);
        $files   = glob("{$dvrDir}/seg_*.ts");
        if (empty($files)) return;

        sort($files);

        if (count($files) > $maxSegs) {
            $excess = array_slice($files, 0, count($files) - $maxSegs);
            foreach ($excess as $f) {
                @unlink($f);
            }
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

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
}
