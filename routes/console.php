<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('dvr:cleanup')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('youtube:cleanup-cache')->everyThirtyMinutes()->withoutOverlapping();

// Refresh working proxy from proxifly repo every 30 minutes
Schedule::call(function () {
    app(\App\Services\ProxyService::class)->refresh();
})->everyThirtyMinutes()->withoutOverlapping();

Schedule::command('youtube:refresh-urls')->everyThirtyMinutes()->withoutOverlapping();

// Update NOW PLAYING overlay for running TV playout channels every minute.
// This is a fallback heartbeat — the per-channel NowPlayingWriter daemon keeps
// the overlay synced to ACTUAL ffmpeg progress (~1s); this ensures any drift
// is corrected even if a writer process dies.
Schedule::call(function () {
    $engine = app(\App\Services\TvPlayoutEngine::class);
    \App\Models\Channel::where('source_type', 'tv_playout')
        ->where('is_active', true)
        ->each(function ($channel) use ($engine) {
            if ($engine->isRunning($channel)) {
                $offset = $engine->readPlayoutOffset($engine->nowPlayingProgressFile($channel));
                $engine->writeMetaFile($channel, $offset);
            }
        });
})->everyMinute()->withoutOverlapping();
