<?php

namespace App\Jobs;

use App\Models\PlaylistItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class DownloadUrlToFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    public function __construct(
        public readonly int    $itemId,
        public readonly string $url,
        public readonly string $destPath,
    ) {}

    public function handle(): void
    {
        $item = PlaylistItem::find($this->itemId);
        if (! $item) {
            return;
        }

        Log::info("[DownloadJob] item {$this->itemId}: downloading {$this->url} → {$this->destPath}");

        $encodedUrl = str_replace(['[', ']'], ['%5B', '%5D'], $this->url);
        $cmd = ['curl', '-L', '--max-time', '3600', '-o', $this->destPath, $encodedUrl];
        $proc = new Process($cmd);
        $proc->setTimeout(3600);
        $proc->run();

        if (! $proc->isSuccessful() || ! file_exists($this->destPath) || filesize($this->destPath) < 1024) {
            @unlink($this->destPath);
            Log::error("[DownloadJob] item {$this->itemId}: download failed");
            return;
        }

        // Probe duration of downloaded file
        $ffprobe = trim((string) shell_exec('which ffprobe 2>/dev/null')) ?: 'ffprobe';
        $out = [];
        exec($ffprobe . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($this->destPath) . ' 2>/dev/null', $out);
        $duration = (float) trim(implode('', $out));

        if ($duration <= 0) {
            @unlink($this->destPath);
            Log::error("[DownloadJob] item {$this->itemId}: could not probe duration after download");
            return;
        }

        $item->update([
            'filepath'   => $this->destPath,
            'duration'   => $duration,
            'media_type' => 'local',
        ]);

        Log::info("[DownloadJob] item {$this->itemId}: done ({$duration}s), rebuilding concat");
        \Artisan::call('tv:rebuild-concat', ['channel' => $item->channel_id]);
    }
}
