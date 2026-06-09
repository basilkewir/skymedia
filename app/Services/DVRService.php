<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\DvrSegment;

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

        // Register new .ts files that aren't yet in DB
        $diskFiles = glob("{$dvrDir}/seg_*.ts") ?: [];
        sort($diskFiles);

        foreach ($diskFiles as $filepath) {
            $filename = basename($filepath);

            if (!file_exists($filepath)) continue;

            $exists = DvrSegment::where('channel_id', $channel->id)
                ->where('filename', $filename)
                ->exists();

            if (!$exists) {
                $seq = $this->extractSeq($filename);
                DvrSegment::create([
                    'channel_id'  => $channel->id,
                    'filename'    => $filename,
                    'filepath'    => $filepath,
                    'duration'    => (float) $channel->segment_duration,
                    'sequence'    => $seq ?? 0,
                    'filesize'    => filesize($filepath) ?: 0,
                    'recorded_at' => now(),
                    'is_available'=> true,
                ]);
            }
        }

        $this->enforceWindow($channel);
    }

    // ---------------------------------------------------------------
    // Enforce rolling DVR window — delete oldest segments over limit
    // ---------------------------------------------------------------

    public function enforceWindow(Channel $channel): void
    {
        $maxSegs = (int) ceil($channel->dvr_duration / $channel->segment_duration);

        // Delete old DB records AND their files
        $cutoff = now()->subSeconds($channel->dvr_duration + $channel->segment_duration);
        $expired = DvrSegment::where('channel_id', $channel->id)
            ->where('recorded_at', '<', $cutoff)
            ->get();

        foreach ($expired as $seg) {
            @unlink($seg->filepath);
            $seg->delete();
        }

        // Hard cap: if still over maxSegs, remove oldest by sequence
        $count = DvrSegment::where('channel_id', $channel->id)->count();
        if ($count > $maxSegs) {
            $toDelete = DvrSegment::where('channel_id', $channel->id)
                ->orderBy('sequence')
                ->limit($count - $maxSegs)
                ->get();

            foreach ($toDelete as $seg) {
                @unlink($seg->filepath);
                $seg->delete();
            }
        }

        // Also enforce on disk directly (catches ffmpeg wrap-around filenames)
        $this->ffmpeg->enforceWindow($channel);
    }

    // ---------------------------------------------------------------
    // Rebuild the concat.txt for DVR playback
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

        $lines = $segments->map(fn($s) => "file '{$s->filepath}'")->toArray();
        file_put_contents($channel->dvr_directory . '/concat.txt', implode("\n", $lines));
        return true;
    }

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

    public function purgeAll(Channel $channel): void
    {
        $dvrDir = $channel->dvr_directory;

        DvrSegment::where('channel_id', $channel->id)->delete();

        if (is_dir($dvrDir)) {
            foreach (glob("{$dvrDir}/*.ts") as $f) {
                @unlink($f);
            }
            foreach (['index.m3u8', 'concat.txt'] as $f) {
                @unlink("{$dvrDir}/{$f}");
            }
        }
    }

    // ---------------------------------------------------------------

    protected function extractSeq(string $filename): ?int
    {
        if (preg_match('/seg_(\d+)\.ts$/', $filename, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}
