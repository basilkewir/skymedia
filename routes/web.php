<?php

declare(strict_types=1);

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChannelContentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DvrController;
use App\Http\Controllers\HlsController;
use App\Http\Controllers\IngestController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified'])->group(function () {

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/status', [DashboardController::class, 'status'])->name('dashboard.status');

    // ── Ingest ────────────────────────────────────────────────────────────────
    Route::post('channels/{channel}/ingest/start', [IngestController::class, 'start'])->name('ingest.start');
    Route::post('channels/{channel}/ingest/stop', [IngestController::class, 'stop'])->name('ingest.stop');
    Route::post('channels/{channel}/ingest/restart', [IngestController::class, 'restart'])->name('ingest.restart');
    Route::get('channels/{channel}/ingest/log', [IngestController::class, 'log'])->name('ingest.log');

    // ── Push (mode-aware) ─────────────────────────────────────────────────────
    Route::post('channels/{channel}/push/start', [PushController::class, 'start'])->name('channels.push.start');
    Route::post('channels/{channel}/push/stop', [PushController::class, 'stop'])->name('channels.push.stop');
    Route::post('channels/{channel}/push/restart', [PushController::class, 'restart'])->name('push.restart');
    Route::get('channels/{channel}/push/log', [PushController::class, 'log'])->name('push.log');

    // ── Channels — specific action routes BEFORE resource (prevents conflicts) ─
    Route::post('channels/{channel}/toggle', [ChannelController::class, 'toggle'])->name('channels.toggle');
    Route::post('channels/{channel}/restart', [ChannelController::class, 'restart'])->name('channels.restart');
    Route::post('channels/{channel}/clone', [ChannelController::class, 'clone'])->name('channels.clone');
    Route::delete('channels/{channel}/dvr', [ChannelController::class, 'purgeDvr'])->name('channels.purge-dvr');
    Route::get('channels/{channel}/status', [ChannelController::class, 'status'])->name('channels.status');
    Route::get('channels/{channel}/probe', [ChannelController::class, 'probe'])->name('channels.probe');
    Route::get('channels/{channel}/diagnose', [ChannelController::class, 'diagnose'])->name('channels.diagnose');
    Route::get('channels/{channel}/logs', [ChannelController::class, 'logs'])->name('channels.logs');
    Route::post('channels/{channel}/fallback-vod', [ChannelController::class, 'uploadFallbackVod'])->name('channels.fallback-vod.upload');
    Route::delete('channels/{channel}/fallback-vod', [ChannelController::class, 'removeFallbackVod'])->name('channels.fallback-vod.remove');
    Route::get('channels/{channel}/content', [ChannelContentController::class, 'index'])->name('channels.content');
    Route::post('channels/{channel}/content/upload', [ChannelContentController::class, 'upload'])->name('channels.content.upload');
    Route::put('channels/{channel}/content', [ChannelContentController::class, 'update'])->name('channels.content.update');
    Route::delete('channels/{channel}/content/{media}', [ChannelContentController::class, 'destroy'])->name('channels.content.destroy');

    // ── Channel Sources (multi-source failover) ─────────────────────────────
    Route::get('channels/{channel}/sources', [\App\Http\Controllers\ChannelSourceController::class, 'index'])->name('channels.sources.index');
    Route::post('channels/{channel}/sources', [\App\Http\Controllers\ChannelSourceController::class, 'store'])->name('channels.sources.store');
    Route::put('channels/{channel}/sources/{source}', [\App\Http\Controllers\ChannelSourceController::class, 'update'])->name('channels.sources.update');
    Route::delete('channels/{channel}/sources/{source}', [\App\Http\Controllers\ChannelSourceController::class, 'destroy'])->name('channels.sources.destroy');
    Route::post('channels/{channel}/sources/{source}/activate', [\App\Http\Controllers\ChannelSourceController::class, 'activate'])->name('channels.sources.activate');

    // ── Channels CRUD resource ────────────────────────────────────────────────
    Route::resource('channels', ChannelController::class);

    // ── DVR — specific routes BEFORE parameterised ones ───────────────────────
    Route::middleware('admin')->group(function () {
    Route::delete('dvr/segment/{segment}', [DvrController::class, 'destroySegment'])->name('dvr.segment.destroy');
    Route::delete('dvr/{channel}/purge', [DvrController::class, 'purge'])->name('dvr.purge');
    Route::get('dvr', [DvrController::class, 'index'])->name('dvr.index');
    Route::get('dvr/{channel}', [DvrController::class, 'show'])->name('dvr.show');

    // ── Logs ──────────────────────────────────────────────────────────────────
    Route::get('logs', [LogController::class, 'index'])->name('logs.index');

    // ── Settings ──────────────────────────────────────────────────────────────
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

    // ── User Management ───────────────────────────────────────────────────────
    Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::get('users/{user}/channels', [UserController::class, 'channels'])->name('users.channels');
    Route::post('users/{user}/channels/{channel}/attach', [UserController::class, 'attachChannel'])->name('users.channels.attach');
    Route::post('users/{user}/channels/{channel}/detach', [UserController::class, 'detachChannel'])->name('users.channels.detach');
    });
});

// ── Public HLS output (live / fallback) ─────────────────────────────────
Route::get('hls/{channel}/{file}', [HlsController::class, 'serve'])
    ->where('file', '.+')
    ->name('hls.serve');

// ── VOD playback (public — playable by anyone with the URL) ──────────────
Route::get('recordings/{recording}/play', [ChannelController::class, 'playRecording'])->name('recordings.play');
