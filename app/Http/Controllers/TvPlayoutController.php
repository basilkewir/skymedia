<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\PlaylistItem;
use App\Services\FFmpegService;
use App\Services\TvPlayoutEngine;
use App\Services\YouTubeMetadataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Process;
use Inertia\Inertia;
use Inertia\Response;

class TvPlayoutController extends Controller
{
    public function __construct(
        protected TvPlayoutEngine $engine,
        protected FFmpegService $ffmpeg,
    ) {}

    /**
     * Show the TV playout control page.
     */
    public function index(Channel $channel): Response
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $items = $channel->playlistItems()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $summary = $this->engine->recalculateSchedule($channel);
        $isRunning = $this->engine->isRunning($channel);

        // Preview URL: nginx serves HLS directly from DVR directory
        $host = config('skymedia.server_ip');
        if ($host === 'localhost') {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        }
        $previewUrl = "http://{$host}/hls/{$channel->slug}/live.m3u8";

        return Inertia::render('Channels/TvPlayout', [
            'channel' => $channel,
            'items' => $items,
            'summary' => $summary,
            'isRunning' => $isRunning,
            'previewUrl' => $previewUrl,
            'isAdmin' => (bool) (auth()->user()->is_admin ?? false),
        ]);
    }

    /**
     * Start the TV playout engine.
     */
    public function start(Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        if ($this->engine->isRunning($channel)) {
            return response()->json(['success' => true, 'message' => 'Already running']);
        }

        $ok = $this->engine->start($channel);

        return $ok
            ? response()->json(['success' => true, 'message' => 'TV playout started'])
            : response()->json(['success' => false, 'error' => 'Failed to start — check playlist items'], 422);
    }

    /**
     * Stop the TV playout engine.
     */
    public function stop(Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $this->engine->stop($channel);

        return response()->json(['success' => true, 'message' => 'TV playout stopped']);
    }

    /**
     * Get live status.
     */
    public function status(Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $item = $channel->playlistItems()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->first();

        return response()->json([
            'is_running' => $this->engine->isRunning($channel),
            'playout_status' => $channel->fresh()->playout_status,
            'playout_pid' => $channel->fresh()->playout_pid,
            'current_item' => $item ? [
                'title' => $item->title,
                'duration' => $item->formatted_duration,
            ] : null,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  PLAYLIST ITEM CRUD
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Add a media file to the playlist.
     */
    public function addItem(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $request->validate([
            'media' => 'required|file|max:2097152|mimes:mp4,mov,mkv,webm,ts,mpeg,mpg,avi',
        ]);

        $file = $request->file('media');
        $directory = $channel->dvr_directory . '/tv_media';
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $filepath = $directory . '/' . $filename;
        $file->move($directory, $filename);

        // Probe exact duration
        $duration = $this->probeDuration($filepath);
        if ($duration <= 0) {
            @unlink($filepath);
            return response()->json(['success' => false, 'error' => 'Could not read media duration. File may be corrupt or unsupported.'], 422);
        }

        $maxOrder = PlaylistItem::where('channel_id', $channel->id)->max('sort_order') ?? 0;

        PlaylistItem::create([
            'channel_id' => $channel->id,
            'title' => $file->getClientOriginalName(),
            'filepath' => $filepath,
            'duration' => $duration,
            'sort_order' => $maxOrder + 1,
        ]);

        // Recalculate schedule
        $this->engine->recalculateSchedule($channel);

        // If playout is running, rebuild the concat file
        if ($this->engine->isRunning($channel)) {
            $this->engine->rebuild($channel);
        }

        return response()->json([
            'success' => true,
            'message' => "Added: {$file->getClientOriginalName()} ({$this->formatDuration($duration)})",
        ]);
    }

    /**
     * Add a YouTube video to the playlist by URL.
     * Uses YouTube Data API v3 for metadata (no bot detection).
     * The actual stream URL is extracted by yt-dlp when the item is about to air.
     */
    public function addYouTube(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $request->validate([
            'youtube_url' => 'required|string|max:500',
        ]);

        $url = $request->input('youtube_url');
        $videoId = YouTubeMetadataService::extractVideoId($url);

        if ($videoId === null) {
            return response()->json(['success' => false, 'error' => 'Invalid YouTube URL. Please provide a valid youtube.com/watch?v= or youtu.be/ link.'], 422);
        }

        // Check for duplicates in this channel
        $exists = PlaylistItem::where('channel_id', $channel->id)
            ->where('filepath', "youtube:{$videoId}")
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'error' => 'This YouTube video is already in the playlist.'], 422);
        }

        // Fetch metadata via YouTube Data API v3
        try {
            $meta = app(YouTubeMetadataService::class)->getVideoDetails($videoId);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => "Could not fetch video details: {$e->getMessage()}"], 422);
        }

        if ($meta['duration'] <= 0) {
            return response()->json(['success' => false, 'error' => 'Could not determine video duration. The video may be live-only or unavailable.'], 422);
        }

        $maxOrder = PlaylistItem::where('channel_id', $channel->id)->max('sort_order') ?? 0;

        PlaylistItem::create([
            'channel_id' => $channel->id,
            'title' => $meta['title'],
            'filepath' => "youtube:{$videoId}",
            'duration' => $meta['duration'],
            'sort_order' => $maxOrder + 1,
        ]);

        $summary = $this->engine->recalculateSchedule($channel);

        return response()->json([
            'success' => true,
            'message' => "Added YouTube: {$meta['title']} ({$this->formatDuration($meta['duration'])})",
        ]);
    }

    /**
     * Remove a playlist item.
     */
    public function destroyItem(Channel $channel, PlaylistItem $item): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        // Delete the file from disk
        if (file_exists($item->filepath)) {
            @unlink($item->filepath);
        }

        $item->delete();

        // Reorder remaining items
        PlaylistItem::where('channel_id', $channel->id)
            ->orderBy('sort_order')
            ->each(function ($item, $index) {
                $item->update(['sort_order' => $index + 1]);
            });

        $this->engine->recalculateSchedule($channel);

        if ($this->engine->isRunning($channel)) {
            $this->engine->rebuild($channel);
        }

        return response()->json(['success' => true, 'message' => 'Item removed']);
    }

    /**
     * Reorder playlist items via drag-and-drop.
     */
    public function reorder(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:playlist_items,id',
            'items.*.sort_order' => 'required|integer|min:1',
        ]);

        foreach ($request->input('items') as $entry) {
            PlaylistItem::where('id', $entry['id'])
                ->where('channel_id', $channel->id)
                ->update(['sort_order' => $entry['sort_order']]);
        }

        $summary = $this->engine->recalculateSchedule($channel);

        if ($this->engine->isRunning($channel)) {
            $this->engine->rebuild($channel);
        }

        $freshItems = $channel->playlistItems()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'items' => $freshItems,
            'summary' => $summary,
        ]);
    }

    /**
     * Recalculate the playlist schedule.
     * Optionally set a custom anchor start time (admin only).
     */
    public function recalculate(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $data = $request->validate([
            'start_time' => 'nullable|string|max:30',
        ]);

        $anchor = $data['start_time'] ?? null;
        $summary = $this->engine->recalculateSchedule($channel, $anchor);

        if ($this->engine->isRunning($channel)) {
            $this->engine->rebuild($channel);
        }

        $freshItems = $channel->playlistItems()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Playlist schedule recalculated',
            'items' => $freshItems,
            'summary' => $summary,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CG CONTROLS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Update the ticker text.
     */
    public function updateTicker(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $request->validate([
            'ticker' => 'required|string|max:5000',
        ]);

        $this->engine->updateTicker($channel, $request->input('ticker'));

        return response()->json(['success' => true, 'message' => 'Ticker updated']);
    }

    /**
     * Update the logo.
     */
    public function updateLogo(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $request->validate([
            'logo' => 'required|file|mimes:png,jpg,jpeg,webp|max:5120',
        ]);

        $file = $request->file('logo');
        $directory = $channel->dvr_directory . '/cg';
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Remove old logo
        foreach (glob($directory . '/logo.*') ?: [] as $old) {
            @unlink($old);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $filepath = $directory . '/logo.' . $ext;
        $file->move($directory, 'logo.' . $ext);

        // Create a ChannelMedia entry
        $media = \App\Models\ChannelMedia::create([
            'channel_id' => $channel->id,
            'type' => 'vod',
            'name' => 'Logo',
            'filepath' => $filepath,
            'mime_type' => $file->getMimeType(),
            'filesize' => $file->getSize(),
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->engine->updateLogo($channel, $media->id);

        return response()->json(['success' => true, 'message' => 'Logo updated']);
    }

    /**
     * Toggle ticker enabled/disabled.
     */
    public function toggleTicker(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $channel->update([
            'ticker_enabled' => !$channel->ticker_enabled,
        ]);

        if ($this->engine->isRunning($channel)) {
            $this->engine->rebuild($channel);
        }

        return response()->json([
            'success' => true,
            'ticker_enabled' => $channel->ticker_enabled,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private function probeDuration(string $filepath): float
    {
        try {
            $proc = new Process([
                config('skymedia.ffprobe_binary', 'ffprobe'),
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $filepath,
            ]);
            $proc->setTimeout(15);
            $proc->run();

            $out = trim($proc->getOutput());
            if ($proc->isSuccessful() && is_numeric($out)) {
                return (float) $out;
            }
        } catch (\Throwable) {
            // ignore
        }

        return 0.0;
    }

    private function formatDuration(float $seconds): string
    {
        $h = floor($seconds / 3600);
        $m = floor(($seconds / 60) % 60);
        $s = floor($seconds % 60);
        return $h > 0 ? "{$h}h {$m}m {$s}s" : ($m > 0 ? "{$m}m {$s}s" : "{$s}s");
    }

    private function ensureAccess(Channel $channel): void
    {
        $user = auth()->user();
        abort_unless($user && (($user->is_admin ?? false) || $channel->user_id === $user->id), 403);
    }
}
