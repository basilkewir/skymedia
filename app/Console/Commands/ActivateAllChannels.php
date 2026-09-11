<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\FFmpegService;
use App\Services\StreamManager;
use Illuminate\Console\Command;

class ActivateAllChannels extends Command
{
    protected $signature   = 'streams:activate-all';
    protected $description = 'Start all active channels — restarts any with dead processes';

    public function handle(StreamManager $manager, FFmpegService $ffmpeg): void
    {
        $channels = Channel::where('is_active', true)->get();

        if ($channels->isEmpty()) {
            $this->info('No active channels.');
            return;
        }

        foreach ($channels as $channel) {
            $needsStart = in_array($channel->stream_status, ['idle', 'stopped', 'error', 'offline']);

            // Also restart channels that appear live but have dead PIDs (after reboot/crash)
            if (!$needsStart && in_array($channel->stream_status, ['live', 'fallback', 'starting'])) {
                $ingestAlive = $channel->pid   && $ffmpeg->isRunning($channel->pid);
                $pushAlive   = $channel->push_pid && $ffmpeg->isRunning($channel->push_pid);

                if (!$ingestAlive && !$pushAlive) {
                    $needsStart = true;
                    $this->line("  [{$channel->name}] stuck as {$channel->stream_status} with dead PIDs — restarting");
                }

                // TV playout: if the playout PID is ALIVE but running OLD code
                // (writing live.m3u8/tv_seg_*.ts instead of the new raw.m3u8),
                // it is an orphan from a previous deployment — restart the
                // channel so the new pipeline takes over and old writers die.
                if (!$needsStart && $channel->isTvPlayout() && $ingestAlive) {
                    $argsOut = [];
                    exec("ps -p {$channel->pid} -o args= 2>/dev/null", $argsOut);
                    $args = trim(implode(' ', $argsOut));
                    $isNewCode = str_contains($args, 'raw_%010d') || str_contains($args, 'raw.m3u8');
                    if ($args !== '' && !$isNewCode) {
                        $needsStart = true;
                        $this->line("  [{$channel->name}] playout PID {$channel->pid} runs OLD code — restarting with new pipeline");
                    }

                    // Duplicate-writer detection: a healthy channel has at most a
                    // playout (raw_%010d) + CG (branded_%010d) writer. If MORE
                    // ffmpeg processes touch this channel's DVR dir, the playlist
                    // is being corrupted by multiple writers (CPU saturation +
                    // frozen output) — force a restart to sweep the duplicates.
                    if (!$needsStart) {
                        // pgrep -f matches ERE; the DVR path (/var/skymedia/dvr/{slug})
                        // contains no regex metacharacters, so a plain pattern works.
                        $writerLines = [];
                        exec("pgrep -f " . escapeshellarg("ffmpeg.*" . $channel->dvr_directory . ".*_%010d\\.ts") . " 2>/dev/null", $writerLines);
                        $writerPids = array_filter($writerLines, fn ($w) => (int) trim($w) > 0);
                        if (count($writerPids) > 2) {
                            $needsStart = true;
                            $this->line("  [{$channel->name}] " . count($writerPids) . " HLS writers detected (expected ≤2) — restarting to sweep duplicates");
                        }
                    }
                }
            }

            if ($needsStart) {
                $this->line("  Starting [{$channel->name}]…");
                $manager->startChannel($channel);
            } else {
                $this->line("  [{$channel->name}] already running — skipped");
            }
        }

        $this->info('Done.');
    }
}
