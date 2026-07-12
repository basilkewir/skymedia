<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\ChannelSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChannelSourceController extends Controller
{
    public function index(Channel $channel): JsonResponse
    {
        $sources = $channel->channelSources()->orderBy('priority')->get();
        return response()->json([
            'sources'          => $sources,
            'current_source_id' => $channel->current_source_id,
        ]);
    }

    public function store(Request $request, Channel $channel): JsonResponse
    {
        $data = $request->validate([
            'source_url'  => 'required|string|max:1000',
            'source_type' => 'required|in:hls,dash,udp,mpegts,rtmp,srt,youtube',
            'priority'    => 'nullable|integer|min:0',
        ]);

        $maxPriority = $channel->channelSources()->max('priority') ?? -1;
        $data['priority'] = $data['priority'] ?? ($maxPriority + 1);

        $source = $channel->channelSources()->create($data);

        // If this is the only source, make it current automatically
        if ($channel->channelSources()->count() === 1) {
            $channel->activateSource($source);
        }

        Log::info("[ChannelSource] Added source [{$source->id}] for {$channel->name}: {$source->source_url}");

        return response()->json($source, 201);
    }

    public function update(Request $request, Channel $channel, ChannelSource $source): JsonResponse
    {
        $this->authorizeSource($channel, $source);

        $data = $request->validate([
            'source_url'  => 'sometimes|string|max:1000',
            'source_type' => 'sometimes|in:hls,udp,mpegts,rtmp,srt,youtube',
            'priority'    => 'nullable|integer|min:0',
            'is_active'   => 'sometimes|boolean',
        ]);

        $source->update($data);

        // If this is the current source, sync the channel fields
        if ($channel->current_source_id === $source->id) {
            $channel->update([
                'source_url'  => $source->source_url,
                'source_type' => $source->source_type,
            ]);
        }

        return response()->json($source);
    }

    public function destroy(Channel $channel, ChannelSource $source): JsonResponse
    {
        $this->authorizeSource($channel, $source);

        $wasCurrent = $channel->current_source_id === $source->id;
        $source->delete();

        if ($wasCurrent) {
            $next = $channel->channelSources()->where('is_active', true)->orderBy('priority')->first();
            if ($next) {
                $channel->activateSource($next);
            } else {
                $channel->update(['current_source_id' => null]);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function activate(Channel $channel, ChannelSource $source): JsonResponse
    {
        $this->authorizeSource($channel, $source);

        $channel->activateSource($source);

        Log::info("[ChannelSource] Activated source [{$source->id}] for {$channel->name}");

        return response()->json(['ok' => true, 'current_source_id' => $source->id]);
    }

    private function authorizeSource(Channel $channel, ChannelSource $source): void
    {
        abort_unless($source->channel_id === $channel->id, 404);
    }
}
