<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\IngestService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class RtmpController extends Controller
{
    /**
     * nginx-rtmp on_publish callback.
     *
     * Called when an encoder starts pushing to rtmp://host:1935/live/{key}.
     * nginx-rtmp writes HLS segments to /tmp/hls/{key}/.
     * This callback starts ffmpeg in the app container to pull from
     * http://rtmp:8081/hls/{key}/live.m3u8 and produce the DVR/live playlists.
     */
    public function onPublish(Request $request): Response
    {
        $key = $request->input('name', '');

        if ($key === '') {
            Log::warning('[RTMP] on_publish rejected — empty stream key');
            return response('rejected', 403);
        }

        $channel = Channel::where('rtmp_input_key', $key)->first();

        if (! $channel) {
            Log::warning("[RTMP] on_publish rejected — unknown key: {$key}");
            return response('rejected', 403);
        }

        if (! $channel->is_active) {
            Log::warning("[RTMP] on_publish rejected — channel {$channel->name} is not active");
            return response('rejected', 403);
        }

        Log::info("[RTMP] on_publish {$channel->name} (key={$key}) — encoder connected to port 1935");

        try {
            /** @var IngestService $ingestService */
            $ingestService = app(IngestService::class);
            $ingestService->startHlsPull($channel);
        } catch (\Throwable $e) {
            Log::error("[RTMP] on_publish {$channel->name} failed to start HLS pull: {$e->getMessage()}");
            return response('error', 500);
        }

        return response('ok', 200);
    }

    /**
     * nginx-rtmp on_publish_done callback.
     *
     * Called when the encoder disconnects from nginx-rtop.
     * Stop the ffmpeg HLS pull process immediately so the monitor
     * can switch to fallback right away without waiting for timeout.
     */
    public function onPublishDone(Request $request): Response
    {
        $key = $request->input('name', '');

        $channel = $key !== '' ? Channel::where('rtmp_input_key', $key)->first() : null;
        $name = $channel?->name ?? $key;

        Log::info("[RTMP] on_publish_done — {$name} disconnected — stopping HLS pull ingest");

        if ($channel) {
            try {
                /** @var IngestService $ingestService */
                $ingestService = app(IngestService::class);
                $ingestService->stop($channel);
                $channel->update([
                    'source_live' => false,
                    'stream_status' => 'offline',
                ]);
            } catch (\Throwable $e) {
                Log::warning("[RTMP] on_publish_done {$name} — failed to stop ingest: {$e->getMessage()}");
            }
        }

        return response('ok', 200);
    }
}
