<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UpdateNowPlaying extends Command
{
    protected $signature = 'tv:update-now-playing';
    protected $description = 'Update NOW PLAYING overlay for running TV playout channels';

    public function handle(): int
    {
        $engine = app(\App\Services\TvPlayoutEngine::class);
        $channels = \App\Models\Channel::where('source_type', 'tv_playout')
            ->where('is_active', true)
            ->get();

        foreach ($channels as $channel) {
            if ($engine->isRunning($channel)) {
                $engine->writeMetaFile($channel);
                $this->info("Updated: {$channel->name}");
            }
        }

        return self::SUCCESS;
    }
}
