<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('dvr:cleanup')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('youtube:cleanup-cache')->everyThirtyMinutes()->withoutOverlapping();

// Refresh working proxy from proxifly repo every 30 minutes
Schedule::call(function () {
    app(\App\Services\ProxyService::class)->refresh();
})->everyThirtyMinutes()->withoutOverlapping();

// Update NOW PLAYING overlay for running TV playout channels every minute
Schedule::call(function () {
    $engine = app(\App\Services\TvPlayoutEngine::class);
    \App\Models\Channel::where('source_type', 'tv_playout')
        ->where('is_active', true)
        ->each(function ($channel) use ($engine) {
            if ($engine->isRunning($channel)) {
                $engine->writeMetaFile($channel);
            }
        });
})->everyMinute()->withoutOverlapping();
