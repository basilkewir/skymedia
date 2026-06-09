<?php

use App\Http\Controllers\API\ChannelApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('channels',                      [ChannelApiController::class, 'index']);
    Route::get('channels/status-all',           [ChannelApiController::class, 'statusAll'])->name('api.channels.status-all');
    Route::get('channels/{channel}',            [ChannelApiController::class, 'show']);
    Route::get('channels/{channel}/status',     [ChannelApiController::class, 'status']);
    Route::get('channels/{channel}/logs',       [ChannelApiController::class, 'logs']);
    Route::post('channels/{channel}/start',     [ChannelApiController::class, 'start']);
    Route::post('channels/{channel}/stop',      [ChannelApiController::class, 'stop']);
    Route::get('stats',                         [ChannelApiController::class, 'stats']);
});
