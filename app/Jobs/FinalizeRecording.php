<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Recording;
use App\Services\FFmpegService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class FinalizeRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        private readonly int $channelId,
        private readonly int $recordingId,
        private readonly string $channelTimezone = 'UTC',
    ) {}

    public function handle(): void
    {
        $recording = Recording::find($this->recordingId);
        $channel   = Channel::find($this->channelId);

        if (!$recording || !$channel) return;
        if ($recording->status !== 'recording') return;

        $filepath = $recording->filepath;
        $filesize = file_exists($filepath) ? (int) filesize($filepath) : 0;

        // Delete zero/near-zero byte files immediately — they are garbage
        if ($filesize < 1024) {
            @unlink($filepath);
            $recording->delete();
            Log::warning("[FinalizeRecording] {$channel->name}: deleted empty file {$filepath}");
            return;
        }

        $ffmpeg = app(FFmpegService::class);

        if (! $ffmpeg->isPlayableFile($filepath)) {
            $recording->update(['status' => 'failed', 'completed_at' => now()]);
            @unlink($filepath);
            Log::warning("[FinalizeRecording] {$channel->name}: file not playable {$filepath}");
            return;
        }

        $duration = $this->probeDuration($filepath) ?? (float) $channel->record_duration;

        $recording->update([
            'status'       => 'completed',
            'duration'     => $duration,
            'filesize'     => $filesize,
            'completed_at' => now(),
        ]);

        $channel->update([
            'fallback_recording_path' => $filepath,
        ]);

        if ($filesize > 0) {
            $channel->increment('storage_used_bytes', $filesize);
        }

        // Prune old VODs — use channel's retention policy
        $keep = max(1, (int) ($channel->keep_recordings ?? 3));
        $this->pruneOld($channel, $keep);

        Log::info(sprintf(
            "[FinalizeRecording] %s completed: %s (%.1fs, %.1f MB)",
            $channel->name, basename($filepath), $duration, $filesize / 1_048_576
        ));
    }

    private function probeDuration(string $file): ?float
    {
        try {
            $proc = new Process([
                config('skymedia.ffprobe_binary', 'ffprobe'),
                '-v', 'quiet', '-print_format', 'json', '-show_format', $file,
            ]);
            $proc->setTimeout(8);
            $proc->run();
            $data = json_decode($proc->getOutput(), true);
            return isset($data['format']['duration']) ? (float) $data['format']['duration'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function pruneOld(Channel $channel, int $keep = 3): void
    {
        $minRetentionHours = max(1, (int) config('skymedia.min_recording_retention_hours', 24));
        $retentionCutoff = now()->subHours($minRetentionHours);

        Recording::where('channel_id', $channel->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->skip($keep)
            ->take(1000)
            ->get()
            ->each(function (Recording $rec) use ($channel, $retentionCutoff) {
                if ($rec->completed_at !== null && $rec->completed_at->gt($retentionCutoff)) {
                    return;
                }
                if ($rec->filepath !== $channel->fallback_recording_path) {
                    @unlink($rec->filepath);
                }
                $rec->delete();
            });

        Recording::where('channel_id', $channel->id)
            ->where('status', 'failed')
            ->where('created_at', '<', $retentionCutoff)
            ->each(function (Recording $rec) {
                @unlink($rec->filepath);
                $rec->delete();
            });
    }
}
