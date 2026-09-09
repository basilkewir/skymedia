<?php

namespace App\Jobs;

use App\Services\FFmpegService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranscodeUrlToTs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;
    public int $tries   = 1;

    public function __construct(
        public readonly string $sourceUrl,
        public readonly string $tsFile,
        public readonly string $lockFile,
        public readonly int    $channelId,
        public readonly bool   $isHls,
        public readonly float  $duration,
    ) {}

    public function handle(FFmpegService $ffmpeg): void
    {
        Log::info("[TranscodeJob] transcoding {$this->sourceUrl} → {$this->tsFile}");

        $bin = $ffmpeg->getBin();

        if ($this->isHls) {
            $codecArgs = ['-t', number_format($this->duration, 3, '.', ''), '-c', 'copy'];
        } else {
            $codecArgs = ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
                          '-c:a', 'aac', '-b:a', '128k', '-ac', '2', '-ar', '48000'];
        }

        $cmd = array_merge(
            [$bin, '-y', '-loglevel', 'error',
             '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
             '-i', $this->sourceUrl],
            $codecArgs,
            ['-f', 'mpegts', $this->tsFile]
        );

        exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>/dev/null', $out, $code);

        @unlink($this->lockFile);

        if ($code !== 0 || !file_exists($this->tsFile) || filesize($this->tsFile) < 1_048_576) {
            Log::error("[TranscodeJob] failed for {$this->sourceUrl}");
            return;
        }

        Log::info("[TranscodeJob] done, rebuilding playlist for channel {$this->channelId}");
        \Artisan::call('tv:rebuild-concat', ['channel' => $this->channelId]);
    }
}
