<?php

namespace App\Jobs;

use App\Models\PlaylistItem;
use App\Services\FFmpegService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractYouTubeUrl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    public function __construct(
        public readonly string $videoId,
        public readonly int    $channelId,
        public readonly string $urlCacheFile,
        public readonly string $muxedTs,
    ) {}

    public function handle(FFmpegService $ffmpeg): void
    {
        $lockFile = $this->urlCacheFile . '.extracting';

        $ytdlp = $this->findYtdlp();
        if ($ytdlp === null) {
            Log::error("[YTExtract] yt-dlp not found");
            @unlink($lockFile);
            return;
        }

        $url          = 'https://www.youtube.com/watch?v=' . $this->videoId;
        $cookieSource = storage_path('app/youtube_cookies_auth.txt');
        $cookieArgs   = (file_exists($cookieSource) && filesize($cookieSource) > 50)
            ? ['--cookies', $cookieSource]
            : [];

        $formats = ['18', '22', 'bestvideo[ext=mp4][height<=1080]+bestaudio[ext=m4a]/bestvideo+bestaudio'];
        $clients = ['tv_embedded', 'web', 'ios'];

        $result = null;
        foreach ($formats as $fmt) {
            foreach ($clients as $client) {
                $cmd = array_merge(
                    [$ytdlp, '--no-warnings', '-g', '--socket-timeout', '20', '--retries', '1',
                     '--format', $fmt, '--no-playlist',
                     '--extractor-args', "youtube:player_client={$client}"],
                    $cookieArgs,
                    [$url]
                );
                $output = []; $exitCode = 0;
                exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>/dev/null', $output, $exitCode);
                $lines = array_values(array_filter(array_map('trim', $output), fn($l) => str_starts_with($l, 'http')));
                if ($exitCode === 0 && count($lines) >= 1) {
                    $result = implode("\n", $lines);
                    Log::info("[YTExtract] {$this->videoId}: got " . count($lines) . " URL(s) via client={$client} fmt={$fmt}");
                    break 2;
                }
            }
        }

        if ($result === null) {
            Log::warning("[YTExtract] {$this->videoId}: all clients failed");
            @unlink($lockFile);
            return;
        }

        file_put_contents($this->urlCacheFile, $result);

        // Mux if two separate streams
        $urls     = explode("\n", $result);
        $videoUrl = trim($urls[0]);
        $audioUrl = isset($urls[1]) ? trim($urls[1]) : null;

        if ($audioUrl !== null) {
            Log::info("[YTExtract] {$this->videoId}: muxing → {$this->muxedTs}");
            $bin = $ffmpeg->getBin();
            $cmd = [$bin, '-y', '-loglevel', 'error',
                    '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
                    '-i', $videoUrl, '-i', $audioUrl,
                    '-c', 'copy', '-map', '0:v:0', '-map', '1:a:0',
                    '-f', 'mpegts', $this->muxedTs];
            exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>/dev/null', $out, $code);
            if ($code !== 0 || !file_exists($this->muxedTs) || filesize($this->muxedTs) < 1_048_576) {
                Log::error("[YTExtract] {$this->videoId}: mux failed");
                @unlink($lockFile);
                return;
            }
        }

        @unlink($lockFile);

        // Trigger playlist rebuild
        \Artisan::call('tv:rebuild-concat', ['channel' => $this->channelId]);
        Log::info("[YTExtract] {$this->videoId}: done, playlist rebuilt");
    }

    private function findYtdlp(): ?string
    {
        foreach (['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp'] as $p) {
            if (is_executable($p)) return $p;
        }
        $found = trim((string) shell_exec('which yt-dlp 2>/dev/null'));
        return $found !== '' ? $found : null;
    }
}
