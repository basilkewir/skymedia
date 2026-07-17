<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FFmpegService;
use Illuminate\Console\Command;

class CleanupProcesses extends Command
{
    protected $signature = 'streams:cleanup-processes {--force : Kill ALL ffmpeg processes immediately} {--status : Show current process count only}';

    protected $description = 'Emergency: kill runaway ffmpeg processes and enforce process limits';

    public function handle(FFmpegService $ffmpeg): int
    {
        $count = $ffmpeg->countFfmpegProcesses();

        $this->info("Current ffmpeg processes: {$count}");
        $this->info("Process cap: 256");

        if ($this->option('status')) {
            return self::SUCCESS;
        }

        if ($count <= 16) {
            $this->info("Process count is within normal limits. No cleanup needed.");
            return self::SUCCESS;
        }

        if ($this->option('force') || $count > 32) {
            $this->warn("Killing excess ffmpeg processes...");
            $killed = $ffmpeg->killAllFfmpeg();
            $this->info("Killed {$killed} processes.");
        } else {
            $this->warn("Process count is elevated ({$count}) but not critical.");
            $this->info("Run with --force to kill all, or the monitor will handle it automatically.");
        }

        $remaining = $ffmpeg->countFfmpegProcesses();
        $this->info("Remaining ffmpeg processes: {$remaining}");

        return self::SUCCESS;
    }
}
