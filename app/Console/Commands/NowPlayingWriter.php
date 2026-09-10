<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\TvPlayoutEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NowPlayingWriter extends Command
{
    protected $signature = 'tv:now-playing-writer {channel : Channel ID}';

    protected $description = 'Poll ffmpeg -progress output and keep the NOW PLAYING overlay synced to actual playback. Spawned per running TV playout channel by TvPlayoutEngine::start().';

    public function handle(TvPlayoutEngine $engine): int
    {
        $channel = Channel::find((int) $this->argument('channel'));

        if (! $channel || $channel->source_type !== 'tv_playout') {
            return self::FAILURE;
        }

        $progressFile = $engine->nowPlayingProgressFile($channel);

        $lastOffset = -1.0;
        $lastWrite  = 0;

        while (true) {
            // Exit as soon as the playout ffmpeg is no longer running — the
            // engine (re)spawns us on start().
            if (! $engine->isRunning($channel->fresh())) {
                break;
            }

            $offset = $engine->readPlayoutOffset($progressFile);

            // Write immediately on startup, then only when playback advances
            // ~1s (or once per 10s as a heartbeat for robustness).
            if ($offset !== null && (abs($offset - $lastOffset) >= 1.0 || (time() - $lastWrite) >= 10)) {
                try {
                    $engine->writeMetaFile($channel->fresh(), $offset);
                    $lastOffset = $offset;
                    $lastWrite  = time();
                } catch (\Throwable $e) {
                    Log::warning("[NowPlaying] {$channel->name}: {$e->getMessage()}");
                }
            }

            usleep(1_000_000); // 1s
        }

        return self::SUCCESS;
    }
}