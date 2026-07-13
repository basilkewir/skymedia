<?php

use App\Http\Controllers\API\ChannelApiController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\RtmpController;
use Illuminate\Support\Facades\Route;

// ── Public health checks (no auth) ─────────────────────────────────────────────
Route::get('health',          [HealthController::class, 'check']);
Route::get('health/live',     [HealthController::class, 'live']);
Route::get('health/ready',    [HealthController::class, 'ready']);
Route::get('health/metrics',  [HealthController::class, 'metrics']);
Route::get('health/resources', [HealthController::class, 'resources']);

// ── nginx-rtmp callbacks (no auth — called internally by rtmp container) ────────
Route::post('rtmp/on-publish', [RtmpController::class, 'onPublish']);
Route::post('rtmp/on-publish-done', [RtmpController::class, 'onPublishDone']);

// ── Authenticated API ──────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('channels',                      [ChannelApiController::class, 'index']);
    Route::get('channels/status-all',           [ChannelApiController::class, 'statusAll'])->name('api.channels.status-all');
    Route::get('channels/{channel}',            [ChannelApiController::class, 'show']);
    Route::get('channels/{channel}/status',     [ChannelApiController::class, 'status']);
    Route::get('channels/{channel}/logs',       [ChannelApiController::class, 'logs']);
    Route::post('channels/{channel}/start',     [ChannelApiController::class, 'start']);
    Route::post('channels/{channel}/stop',      [ChannelApiController::class, 'stop']);
    Route::post('channels/bulk/start',          [ChannelApiController::class, 'bulkStart']);
    Route::post('channels/bulk/stop',           [ChannelApiController::class, 'bulkStop']);
    Route::get('stats',                         [ChannelApiController::class, 'stats']);
});