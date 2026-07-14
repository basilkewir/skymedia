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
     * nginx-rtop needs to start writing HLS segments BEFORE ffmpeg can pull.
     *
     * Strategy: return 200 immediately (so nginx-rtop accepts the publish and
     * starts writing HLS), then dispatch a delayed job to start the ffmpeg
     * HLS pull.  The 3-second delay gives nginx-rtop time to generate the
     * first HLS segments so ffmpeg doesn't hit a 404.
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

        Log::info("[RTMP] on_publish {$channel->name} (key={$key}) — encoder connected to port 1935");

        // Auto-activate the channel if it is not already running.
        // This ensures OBS/vMix can push at any time without the operator
        // having to manually start the channel first.
        if (! $channel->is_active || in_array($channel->stream_status, ['stopped', 'idle', 'error'], true)) {
            $channel->update(['is_active' => true, 'stream_status' => 'starting']);
            Log::info("[RTMP] on_publish {$channel->name} — auto-activating channel");
        }

        // Return 200 IMMEDIATELY so nginx-rtmp accepts the publish and
        // starts writing HLS segments. Then dispatch a delayed job to
        // start the ffmpeg HLS pull — the delay gives nginx-rtmp time
        // to generate the first segments.
        \App\Jobs\StartHlsPullIngest::dispatch($channel->id)
            ->delay(now()->addSeconds(3));

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

        Log::info("[RTMP] on_publish_done — {$name} disconnected");

        if ($channel) {
            try {
                /** @var IngestService $ingestService */
                $ingestService = app(IngestService::class);
                $ingestService->stop($channel);
                $channel->update([
                    'source_live'   => false,
                    'stream_status' => 'offline',
                    'pid'           => null,
                ]);
                Log::info("[RTMP] on_publish_done {$name} — ingest stopped, monitor will switch to fallback");
            } catch (\Throwable $e) {
                Log::warning("[RTMP] on_publish_done {$name} — failed to stop ingest: {$e->getMessage()}");
            }
        }

        return response('ok', 200);
    }
}
