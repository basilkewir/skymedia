<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Setting;
use App\Services\FFmpegService;
use App\Services\StreamManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorStreams extends Command
{
    protected $signature = 'streams:monitor {--channel= : Limit to one channel ID}';

    protected $description = 'Long-running stream monitor daemon — never leave the output offline';

    private array $lastChecked = [];

    private array $lastAutoRestart = [];

    private array $consecutiveFailures = [];

    private int $restartsInWindow = 0;

    private int $windowStart = 0;

    private const RESTART_WINDOW_SECONDS = 300;

    private const MAX_RESTARTS_PER_WINDOW = 50;

    public function handle(StreamManager $manager, FFmpegService $ffmpeg): void
    {
        $this->info('SkyMedia Monitor started — PID ' . getmypid());
        $tick = max(1, (int) (Setting::get('monitor_tick') ?? config('skymedia.monitor_tick', 3)));

        while (true) {
            if ((time() - $this->windowStart) > self::RESTART_WINDOW_SECONDS) {
                $this->restartsInWindow = 0;
                $this->windowStart = time();
            }

            try {
                $ffmpegCount = $ffmpeg->countFfmpegProcesses();
                if ($ffmpegCount > 50) {
                    Log::warning("[Monitor] High ffmpeg process count: {$ffmpegCount} — enforcing cap");
                    $ffmpeg->enforceProcessCap();
                }

                $manager->syncMediaMtxPublishers();

                $query = Channel::where('is_active', true);
                if ($id = $this->option('channel')) {
                    $query->where('id', (int) $id);
                }

                $query->each(function (Channel $channel) use ($manager) {
                    $interval = max(1, (int) $channel->check_interval);
                    $lastCheck = $this->lastChecked[$channel->id] ?? 0;

                    if ((time() - $lastCheck) >= $interval) {
                        try {
                            $manager->monitorChannel($channel->fresh());
                        } catch (\Throwable $e) {
                            Log::error("Monitor failed for {$channel->name}: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");
                        }
                        $this->lastChecked[$channel->id] = time();

                        $ch = $channel->fresh();
                        $this->line(sprintf(
                            '[%s] %-22s  ingest=%-10s  push=%-10s  rec=%-10s  src=%s',
                            now()->format('H:i:s'),
                            mb_substr($ch->name, 0, 22),
                            $ch->stream_status,
                            $ch->push_status,
                            $ch->record_status,
                            $ch->source_live ? 'LIVE' : 'DOWN'
                        ));
                    }

                    $ch = $channel->fresh();
                    if (($ch->stream_status === 'offline' || $ch->stream_status === 'starting')
                        && ! $ch->source_live
                        && ! $manager->isIngestRunning($ch)) {

                        $failures = $this->consecutiveFailures[$ch->id] ?? 0;
                        $this->consecutiveFailures[$ch->id] = $failures + 1;

                        if ($this->restartsInWindow >= self::MAX_RESTARTS_PER_WINDOW) {
                            $this->line(sprintf(
                                '[%s] %-22s  SKIPPED: restart window cap reached (%d/%d)',
                                now()->format('H:i:s'),
                                mb_substr($ch->name, 0, 22),
                                $this->restartsInWindow,
                                self::MAX_RESTARTS_PER_WINDOW
                            ));
                            return;
                        }

                        $lastRestart = $this->lastAutoRestart[$ch->id] ?? 0;

                        $base = $ch->isPushIngest() ? 12 : 8;
                        $jitter = $ch->id % 5;
                        $backoff = min(300, $failures * 5);
                        $cooldown = $base + $jitter + $backoff;

                        if ((time() - $lastRestart) < $cooldown) {
                            return;
                        }

                        try {
                            if ($ch->isPushIngest() && $ch->source_type === 'srt') {
                                if (! $manager->isListenerLoopRunning($ch)) {
                                    $manager->restartChannel($ch);
                                    $this->lastAutoRestart[$ch->id] = time();
                                    $this->restartsInWindow++;
                                    $this->line(sprintf(
                                        '[%s] %-22s  AUTO-RESTART: listener loop restarted (failures: %d)',
                                        now()->format('H:i:s'),
                                        mb_substr($ch->name, 0, 22),
                                        $failures + 1
                                    ));
                                }
                            } elseif ($ch->isPushIngest() && $ch->source_type === 'rtmp') {
                            } else {
                                $manager->refreshIngest($ch);
                                $this->lastAutoRestart[$ch->id] = time();
                                $this->restartsInWindow++;
                                $this->line(sprintf(
                                    '[%s] %-22s  AUTO-REFRESH: trying next source (cd=%ds, failures=%d)',
                                    now()->format('H:i:s'),
                                    mb_substr($ch->name, 0, 22),
                                    $cooldown,
                                    $failures + 1
                                ));
                            }
                        } catch (\Throwable $e) {
                            Log::error("Auto-recovery failed for {$ch->name}: {$e->getMessage()}");
                        }
                    } else {
                        unset($this->consecutiveFailures[$ch->id]);
                    }
                });

            } catch (\Throwable $e) {
                Log::error('Monitor loop error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $this->error($e->getMessage());
            }

            sleep($tick);
        }
    }
}
