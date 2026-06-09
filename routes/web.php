<?php

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DvrController;
use App\Http\Controllers\IngestController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified'])->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Channels (CRUD + overview)
    Route::resource('channels', ChannelController::class);
    Route::get('channels/{channel}/probe', [ChannelController::class, 'probe'])->name('channels.probe');

    // ── Ingest (Source) controls ──────────────────────────────────
    Route::post('channels/{channel}/ingest/start',   [IngestController::class, 'start'])->name('ingest.start');
    Route::post('channels/{channel}/ingest/stop',    [IngestController::class, 'stop'])->name('ingest.stop');
    Route::post('channels/{channel}/ingest/restart', [IngestController::class, 'restart'])->name('ingest.restart');
    Route::get('channels/{channel}/ingest/log',      [IngestController::class, 'log'])->name('ingest.log');

    // ── DVR controls ──────────────────────────────────────────────
    Route::get('dvr',                               [DvrController::class, 'index'])->name('dvr.index');
    Route::get('dvr/{channel}',                     [DvrController::class, 'show'])->name('dvr.show');
    Route::delete('dvr/segment/{segment}',          [DvrController::class, 'destroySegment'])->name('dvr.segment.destroy');
    Route::delete('dvr/{channel}/purge',            [DvrController::class, 'purge'])->name('dvr.purge');

    // ── Push controls ─────────────────────────────────────────────
    Route::post('channels/{channel}/push/start',    [PushController::class, 'start'])->name('push.start');
    Route::post('channels/{channel}/push/stop',     [PushController::class, 'stop'])->name('push.stop');
    Route::post('channels/{channel}/push/restart',  [PushController::class, 'restart'])->name('push.restart');
    Route::get('channels/{channel}/push/log',       [PushController::class, 'log'])->name('push.log');

    // Logs
    Route::get('logs', [LogController::class, 'index'])->name('logs.index');

    // Settings
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
});
