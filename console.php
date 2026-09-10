<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('dvr:cleanup')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('youtube:cleanup-cache')->everyThirtyMinutes()->withoutOverlapping();

// Refresh working proxy from proxifly repo every 30 minutes
Schedule::call(function () {
    app(\App\Services\ProxyService::class)->refresh();
})->everyThirtyMinutes()->withoutOverlapping();

Schedule::command('youtube:refresh-urls')->everyThirtyMinutes()->withoutOverlapping();

// Update NOW PLAYING overlay for running TV playout channels every minute
Schedule::command('tv:update-now-playing')->everyMinute()->withoutOverlapping();
