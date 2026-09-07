<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\TvPlayoutEngine;
use Illuminate\Console\Command;

class RebuildConcatFile extends Command
{
    protected $signature = 'tv:rebuild-concat {channel : Channel ID}';

    protected $description = 'Rebuild the TV playout concat file for a channel (called after YouTube download completes)';

    public function handle(TvPlayoutEngine $engine): int
    {
        $channel = Channel::find((int) $this->argument('channel'));

        if (! $channel || $channel->source_type !== 'tv_playout') {
            return self::FAILURE;
        }

        $engine->rebuild($channel);

        $this->info("Concat rebuilt for channel {$channel->id}");

        return self::SUCCESS;
    }
}
