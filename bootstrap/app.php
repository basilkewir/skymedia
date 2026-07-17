<?php

declare(strict_types=1);

use App\Http\Middleware\ChannelLocale;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        health: '/api/health/live',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['admin' => EnsureAdmin::class]);
        $middleware->web(append: [
            HandleInertiaRequests::class,
            ChannelLocale::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(prepend: [
            ThrottleRequests::class . ':api',
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('dvr:cleanup')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('disk:cleanup --target=95')->everyTwoMinutes()->withoutOverlapping();
        $schedule->command('streams:schedule')->everyMinute()->withoutOverlapping();
    })
    ->withCommands([
        __DIR__ . '/../app/Console/Commands',
    ])
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->expectsJson() && !$request->header('X-Inertia')) {
                $status = match (true) {
                    $e instanceof AuthenticationException => 401,
                    $e instanceof AuthorizationException => 403,
                    $e instanceof ValidationException => 422,
                    $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                    default => 500,
                };

                return response()->json([
                    'error' => $e->getMessage(),
                    'code' => $status,
                ], $status);
            }
        });
    })->create();
