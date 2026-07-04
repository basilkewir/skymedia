<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use App\Models\DvrSegment;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class DVRService
{
    // ---------------------------------------------------------------
    // Sync — register new segments written by the ingest process
    // ---------------------------------------------------------------

    /**
     * Scan the DVR directory for new .ts segments and register them in
     * the database. Updates filesizes for in-progress segments.
     * After sync, enforces the rolling window.
     */
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
                // Update filesize if segment was still being written
                if ($known[$filename]->filesize !== $diskSize) {
                    $known[$filename]->update(['filesize' => $diskSize]);
                }
                continue;
            }

            $seq = $this->extractSeq($filename);
            if ($seq === null) continue;

            // Use ffprobe for accurate duration; fall back to channel default
            $duration = $this->probeSegmentDuration($filepath) ?? (float) $channel->segment_duration;

            $segment = DvrSegment::create([
                'channel_id'   => $channel->id,
                'filename'     => $filename,
                'filepath'     => $filepath,
                'duration'     => $duration,
                'sequence'     => $seq,
                'filesize'     => $diskSize,
                'recorded_at'  => now(),
                'is_available' => true,
            ]);

            // Increment channel storage usage
            if ($diskSize > 0) {
                $channel->increment('storage_used_bytes', $diskSize);
            }
        }

        $this->enforceWindow($channel);
    }

    // ---------------------------------------------------------------
    // Rolling-window enforcement
    // ---------------------------------------------------------------

    /**
     * Keeps the DVR buffer to exactly dvr_duration seconds.
     *
     * Strategy (both checks run every sync):
     *  1. Time-based: delete any segment whose recorded_at is older than
     *     dvr_duration seconds ago.
     *  2. Count-based safety cap: if more segments remain than the maximum
     *     theoretical count (dvr_duration / segment_duration), delete the
     *     oldest by sequence number.
     *
     * This means a 3-hour DVR always stores the last 3 hours and nothing
     * more, regardless of how long the channel has been running.
     */
    public function enforceWindow(Channel $channel): void
    {
        $dvr     = max(60, (int) $channel->dvr_duration);
        $segDur  = max(1,  (int) $channel->segment_duration);
        $maxSegs = (int) ceil($dvr / $segDur);

        // 1. Time-based: delete segments older than the DVR window
        $cutoff = now()->subSeconds($dvr);

        DvrSegment::where('channel_id', $channel->id)
            ->where('recorded_at', '<', $cutoff)
            ->chunkById(200, function ($segs) {
                foreach ($segs as $seg) {
                    $this->deleteSegment($seg);
                }
            });

        // 2. Count cap: trim oldest by sequence only if still over limit
        $count = DvrSegment::where('channel_id', $channel->id)->count();
        if ($count > $maxSegs) {
            DvrSegment::where('channel_id', $channel->id)
                ->orderBy('sequence')
                ->limit($count - $maxSegs)
                ->get()
                ->each(fn($seg) => $this->deleteSegment($seg));
        }
    }

    // ---------------------------------------------------------------
    // Build concat.txt for DVR looping playback
    // ---------------------------------------------------------------

    /**
     * Writes a concat.txt file listing all available segments in order.
     * Falls back to a raw disk glob if the database has no entries.
     */
    public function buildConcatFile(Channel $channel): bool
    {
        $segments = DvrSegment::where('channel_id', $channel->id)
            ->where('is_available', true)
            ->orderBy('sequence')
            ->get();

        if ($segments->isEmpty()) {
            $files = glob($channel->dvr_directory . '/seg_*.ts') ?: [];
            if (empty($files)) return false;
            sort($files, SORT_NATURAL);
            $lines = array_map(fn($f) => "file '" . str_replace("'", "'\\''", $f) . "'", $files);
        } else {
            $lines = $segments
                ->map(fn($s) => "file '" . str_replace("'", "'\\''", $s->filepath) . "'")
                ->all();
        }

        file_put_contents($channel->dvr_directory . '/concat.txt', implode("\n", $lines));
        return true;
    }

    // ---------------------------------------------------------------
    // Queries
    // ---------------------------------------------------------------

    public function hasSegments(Channel $channel): bool
    {
        if (DvrSegment::where('channel_id', $channel->id)->exists()) {
            return true;
        }
        return !empty(glob($channel->dvr_directory . '/seg_*.ts'));
    }

    public function totalDuration(Channel $channel): float
    {
        return (float) DvrSegment::where('channel_id', $channel->id)
            ->where('is_available', true)
            ->sum('duration');
    }

    public function totalSize(Channel $channel): int
    {
        return (int) DvrSegment::where('channel_id', $channel->id)
            ->where('is_available', true)
            ->sum('filesize');
    }

    public function segmentCount(Channel $channel): int
    {
        return DvrSegment::where('channel_id', $channel->id)->count();
    }

    /**
     * How full the DVR buffer is as a percentage of dvr_duration.
     */
    public function bufferPercent(Channel $channel): int
    {
        if ($channel->dvr_duration <= 0) return 0;
        $pct = ($this->totalDuration($channel) / $channel->dvr_duration) * 100;
        return min(100, (int) round($pct));
    }

    // ---------------------------------------------------------------
    // Purge
    // ---------------------------------------------------------------

    public function purgeAll(Channel $channel): int
    {
        $deleted = 0;
        $totalSize = 0;

        DvrSegment::where('channel_id', $channel->id)
            ->each(function (DvrSegment $seg) use (&$deleted, &$totalSize) {
                @unlink($seg->filepath);
                $totalSize += $seg->filesize ?? 0;
                $seg->delete();
                $deleted++;
            });

        // Decrement channel storage usage
        if ($totalSize > 0) {
            $channel->decrement('storage_used_bytes', $totalSize);
        }

        foreach (['concat.txt', 'live.m3u8'] as $f) {
            @unlink("{$channel->dvr_directory}/{$f}");
        }

        // Also remove any orphan .ts files
        foreach (glob("{$channel->dvr_directory}/seg_*.ts") ?: [] as $f) {
            @unlink($f);
            $deleted++;
        }

        return $deleted;
    }

    // ---------------------------------------------------------------
    // Integrity checks
    // ---------------------------------------------------------------

    public function verifySegments(Channel $channel): void
    {
        DvrSegment::where('channel_id', $channel->id)
            ->each(function (DvrSegment $seg) {
                $available = file_exists($seg->filepath) && filesize($seg->filepath) > 0;
                if ($seg->is_available !== $available) {
                    $seg->update(['is_available' => $available]);
                }
            });
    }

    public function cleanupOrphans(Channel $channel): int
    {
        $cleaned = 0;

        DvrSegment::where('channel_id', $channel->id)
            ->each(function (DvrSegment $seg) use (&$cleaned) {
                if (!file_exists($seg->filepath)) {
                    $seg->delete();
                    $cleaned++;
                }
            });

        return $cleaned;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    protected function deleteSegment(DvrSegment $seg): void
    {
        @unlink($seg->filepath);
        $size = $seg->filesize ?? 0;
        $seg->delete();

        // Update channel storage usage
        if ($size > 0) {
            $seg->channel->decrement('storage_used_bytes', $size);
        }
    }

    protected function extractSeq(string $filename): ?int
    {
        return preg_match('/seg_(\d+)\.ts$/', $filename, $m) ? (int) $m[1] : null;
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
            $dur  = $data['format']['duration'] ?? null;
            return $dur ? (float) $dur : null;
        } catch (\Throwable $e) {
            Log::debug("Segment probe failed: {$e->getMessage()}");
            return null;
        }
    }
}
