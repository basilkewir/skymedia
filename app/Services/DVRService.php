<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\DvrSegment;
use Illuminate\Support\Facades\Log;

class DVRService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    // ---------------------------------------------------------------
    // Called periodically while stream is LIVE to sync DB from disk
    // ---------------------------------------------------------------

    public function syncSegments(Channel $channel): void
    {
        $dvrDir = $channel->dvr_directory;
        if (!is_dir($dvrDir)) return;

        $diskFiles = glob("{$dvrDir}/seg_*.ts") ?: [];
        natsort($diskFiles);
        $diskFiles = array_values($diskFiles);

        $known = DvrSegment::where('channel_id', $channel->id)
            ->get()
            ->keyBy('filename');

        foreach ($diskFiles as $filepath) {
            if (!is_file($filepath)) continue;

            $filename = basename($filepath);
            $diskSize = filesize($filepath) ?: 0;

            if (isset($known[$filename])) {
                $seg = $known[$filename];
                if ($seg->filesize !== $diskSize) {
                    $seg->update(['filesize' => $diskSize]);
                }
                continue;
            }

            $seq = $this->extractSeq($filename);
            if ($seq === null) continue;

            $segmentDuration = $this->probeSegmentDuration($filepath) ?: (float) $channel->segment_duration;

            DvrSegment::create([
                'channel_id'  => $channel->id,
                'filename'    => $filename,
                'filepath'    => $filepath,
                'duration'    => $segmentDuration,
                'sequence'    => $seq,
                'filesize'    => $diskSize,
                'recorded_at' => now(),
                'is_available'=> true,
            ]);
        }

        $this->enforceWindow($channel);
    }

    // ---------------------------------------------------------------
    // Enforce rolling DVR window — delete oldest segments over limit
    // Uses BOTH time-based and count-based enforcement
    // ---------------------------------------------------------------

    public function enforceWindow(Channel $channel): void
    {
        $maxSegs = max(1, (int) ceil($channel->dvr_duration / max(1, $channel->segment_duration)));

        $cutoff = now()->subSeconds($channel->dvr_duration + $channel->segment_duration);
        $expired = DvrSegment::where('channel_id', $channel->id)
            ->where('recorded_at', '<', $cutoff)
            ->get();

        foreach ($expired as $seg) {
            $this->deleteSegment($seg);
        }

        $count = DvrSegment::where('channel_id', $channel->id)->count();
        if ($count > $maxSegs) {
            $toDelete = DvrSegment::where('channel_id', $channel->id)
                ->orderBy('sequence')
                ->limit($count - $maxSegs)
                ->get();

            foreach ($toDelete as $seg) {
                $this->deleteSegment($seg);
            }
        }
    }

    // ---------------------------------------------------------------
    // Rebuild the concat.txt for DVR playback from DB records
    // ---------------------------------------------------------------

    public function buildConcatFile(Channel $channel): bool
    {
        $segments = DvrSegment::where('channel_id', $channel->id)
            ->where('is_available', true)
            ->orderBy('sequence')
            ->get();

        if ($segments->isEmpty()) {
            return $this->ffmpeg->buildConcatFile($channel);
        }

        $lines = $segments->map(fn($s) => "file '" . str_replace("'", "'\\''", $s->filepath) . "'")->toArray();
        file_put_contents($channel->dvr_directory . '/concat.txt', implode("\n", $lines));
        return true;
    }

    // ---------------------------------------------------------------
    // Segment availability checks
    // ---------------------------------------------------------------

    public function hasSegments(Channel $channel): bool
    {
        if (DvrSegment::where('channel_id', $channel->id)->exists()) {
            return true;
        }
        $files = glob($channel->dvr_directory . '/seg_*.ts');
        return !empty($files);
    }

    public function totalDuration(Channel $channel): float
    {
        return (float) DvrSegment::where('channel_id', $channel->id)
            ->where('is_available', true)
            ->sum('duration');
    }

    public function totalSize(Channel $channel): int
    {
        return (int) DvrSegment::where('channel_id', $channel->id)->sum('filesize');
    }

    public function segmentCount(Channel $channel): int
    {
        return DvrSegment::where('channel_id', $channel->id)->count();
    }

    // ---------------------------------------------------------------
    // Purge all DVR data for a channel
    // ---------------------------------------------------------------

    public function purgeAll(Channel $channel): int
    {
        $dvrDir = $channel->dvr_directory;
        $deleted = 0;

        $segments = DvrSegment::where('channel_id', $channel->id)->get();
        foreach ($segments as $seg) {
            @unlink($seg->filepath);
            $seg->delete();
            $deleted++;
        }

        foreach (['concat.txt', 'index.m3u8'] as $f) {
            @unlink("{$dvrDir}/{$f}");
        }

        return $deleted;
    }

    // ---------------------------------------------------------------
    // Mark segments as unavailable (when files are missing)
    // ---------------------------------------------------------------

    public function verifySegments(Channel $channel): void
    {
        $segments = DvrSegment::where('channel_id', $channel->id)->get();
        foreach ($segments as $seg) {
            if (!file_exists($seg->filepath)) {
                $seg->update(['is_available' => false]);
            }
        }
    }

    // ---------------------------------------------------------------
    // Clean up orphaned files (segments in DB that don't exist on disk)
    // ---------------------------------------------------------------

    public function cleanupOrphans(Channel $channel): int
    {
        $cleaned = 0;
        $segments = DvrSegment::where('channel_id', $channel->id)->get();
        foreach ($segments as $seg) {
            if (!file_exists($seg->filepath)) {
                $seg->delete();
                $cleaned++;
            }
        }
        return $cleaned;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    protected function deleteSegment(DvrSegment $seg): void
    {
        @unlink($seg->filepath);
        $seg->delete();
    }

    protected function extractSeq(string $filename): ?int
    {
        if (preg_match('/seg_(\d+)\.ts$/', $filename, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    protected function probeSegmentDuration(string $filepath): ?float
    {
        try {
            $proc = new \Symfony\Component\Process\Process([
                config('skymedia.ffprobe_binary', 'ffprobe'),
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_format',
                $filepath,
            ]);
            $proc->setTimeout(3);
            $proc->run();

            if (!$proc->isSuccessful()) return null;

            $data = json_decode($proc->getOutput(), true);
            $dur = $data['format']['duration'] ?? null;
            return $dur ? (float) $dur : null;
        } catch (\Throwable $e) {
            Log::debug("Segment probe failed: {$e->getMessage()}");
            return null;
        }
    }
}
