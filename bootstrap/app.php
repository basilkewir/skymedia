<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('dvr:cleanup')->everyFiveMinutes()->withoutOverlapping();
    })
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
