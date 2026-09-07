<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('dvr:cleanup')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('youtube:cleanup-cache')->everyThirtyMinutes()->withoutOverlapping();

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

// Refresh YouTube stream URLs before they expire (every 30 minutes)
Schedule::call(function () {
    $tvChannels = \App\Models\Channel::where('source_type', 'tv_playout')
        ->where('is_active', true)
        ->get();

    $engine = app(\App\Services\TvPlayoutEngine::class);
    foreach ($tvChannels as $channel) {
        try {
            $engine->refreshYouTubeUrls($channel);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("[YouTubeRefresh] Failed for {$channel->name}: {$e->getMessage()}");
        }
    }

    // Delayed rebuild — after prefetch jobs have had time to extract URLs
    $pendingBuilds = cache()->get('yt_refresh_rebuild_pending', []);
    if (! empty($pendingBuilds)) {
        $engine2 = app(\App\Services\TvPlayoutEngine::class);
        foreach ($pendingBuilds as $chId) {
            $ch = \App\Models\Channel::find($chId);
            if ($ch && $engine2->isRunning($ch)) {
                $engine2->rebuild($ch);
                \Illuminate\Support\Facades\Log::info("[YouTubeRefresh] Rebuilt concat for {$ch->name}");
            }
        }
        cache()->forget('yt_refresh_rebuild_pending');
    }
})->everyThirtyMinutes()->withoutOverlapping();
