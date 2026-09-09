<?php

namespace App\Jobs;

use App\Services\FFmpegService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MuxVideoAudio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    public function __construct(
        public readonly string $videoId,
        public readonly string $videoUrl,
        public readonly string $audioUrl,
        public readonly string $outputTs,
        public readonly int    $channelId,
    ) {}

    public function handle(FFmpegService $ffmpeg): void
    {
        $lockFile = $this->outputTs . '.muxing';

        Log::info("[MuxJob] {$this->videoId}: muxing → {$this->outputTs}");

        $bin = $ffmpeg->getBin();
        $cmd = [$bin, '-y', '-loglevel', 'error',
                '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
                '-i', $this->videoUrl, '-i', $this->audioUrl,
                '-c', 'copy', '-map', '0:v:0', '-map', '1:a:0',
                '-f', 'mpegts', $this->outputTs];

        exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>/dev/null', $out, $code);

        @unlink($lockFile);

        if ($code !== 0 || !file_exists($this->outputTs) || filesize($this->outputTs) < 1_048_576) {
            Log::error("[MuxJob] {$this->videoId}: mux failed");
            return;
        }

        Log::info("[MuxJob] {$this->videoId}: done, rebuilding playlist");
        \Artisan::call('tv:rebuild-concat', ['channel' => $this->channelId]);
    }
}
