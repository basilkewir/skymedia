<?php

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DvrController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified'])->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Channels
    Route::resource('channels', ChannelController::class);
    Route::post('channels/{channel}/toggle',    [ChannelController::class, 'toggle'])->name('channels.toggle');
    Route::post('channels/{channel}/restart',   [ChannelController::class, 'restart'])->name('channels.restart');
    Route::delete('channels/{channel}/dvr',     [ChannelController::class, 'purgeDvr'])->name('channels.purge-dvr');
    Route::get('channels/{channel}/probe',      [ChannelController::class, 'probe'])->name('channels.probe');

    // DVR
    Route::get('dvr',                        [DvrController::class, 'index'])->name('dvr.index');
    Route::get('dvr/{channel}',              [DvrController::class, 'show'])->name('dvr.show');
    Route::delete('dvr/segment/{segment}',   [DvrController::class, 'destroySegment'])->name('dvr.segment.destroy');
    Route::delete('dvr/{channel}/purge',     [DvrController::class, 'purge'])->name('dvr.purge');

    // Logs
    Route::get('logs', [LogController::class, 'index'])->name('logs.index');

    // Settings
    Route::get('settings',  [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings',  [SettingsController::class, 'update'])->name('settings.update');
});
