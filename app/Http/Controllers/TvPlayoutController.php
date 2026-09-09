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

        // Build summary from stored scheduled times — do NOT mutate on every page load.
        // recalculateSchedule is only called explicitly (start, add item, reorder, etc.).
        $totalDuration = $items->sum('duration');
        $firstItem = $items->first();
        $lastItem  = $items->last();
        $summary = [
            'total_duration_seconds' => $totalDuration,
            'formatted_total'        => $this->formatDurationLong((float) $totalDuration),
            'item_count'             => $items->count(),
            'anchor_start'           => $firstItem?->scheduled_start?->toIso8601String(),
            'end_anchor'             => $lastItem?->scheduled_end?->toIso8601String(),
        ];

        $isRunning = $this->engine->isRunning($channel);

        // Get download statuses for YouTube items
        $downloadStatuses = [];
        foreach ($items as $item) {
            if ($item->isYouTube()) {
                $downloadStatuses[$item->id] = $this->engine->getYouTubeDownloadStatus($item);
            }
        }

        // Preview URL: nginx serves HLS directly from DVR directory on port 8080
        $host = config('skymedia.server_ip');
        if ($host === 'localhost') {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        }
        $previewUrl = "http://{$host}:8080/hls/{$channel->slug}/live.m3u8";

        return Inertia::render('Channels/TvPlayout', [
            'channel'         => $channel,
            'items'           => $items,
            'summary'         => $summary,
            'isRunning'       => $isRunning,
            'previewUrl'      => $previewUrl,
            'downloadStatuses' => $downloadStatuses,
            'isAdmin'         => (bool) (auth()->user()->is_admin ?? false),
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
            'push_status' => $channel->fresh()->push_status,
            'push_running' => $this->engine->isPushRunning($channel),
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
            'youtube_url' => 'required|string|max:2000',
        ]);

        $url = $request->input('youtube_url');
        $videoId = YouTubeMetadataService::extractVideoId($url);

        if ($videoId !== null) {
            return $this->addYouTubeById($channel, $videoId);
        }

        if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('#googlevideo\.com/videoplayback#i', $url)) {
            return $this->addStreamUrl($channel, $url);
        }

        return response()->json(['success' => false, 'error' => 'Invalid YouTube URL. Please provide a youtube.com/watch?v= link, a youtu.be/ link, a bare video ID, or a direct stream URL.'], 422);
    }

    private function addYouTubeById(Channel $channel, string $videoId): JsonResponse
    {
        $exists = PlaylistItem::where('channel_id', $channel->id)
            ->where('filepath', "youtube:{$videoId}")
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'error' => 'This YouTube video is already in the playlist.'], 422);
        }

        try {
            $meta = app(YouTubeMetadataService::class)->getVideoDetails($videoId);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => "Could not fetch video details: {$e->getMessage()}"], 422);
        }

        if ($meta['duration'] <= 0) {
            return response()->json(['success' => false, 'error' => 'Could not determine video duration. The video may be live-only or unavailable.'], 422);
        }

        $maxOrder = PlaylistItem::where('channel_id', $channel->id)->max('sort_order') ?? 0;

        $item = PlaylistItem::create([
            'channel_id' => $channel->id,
            'title' => $meta['title'],
            'filepath' => "youtube:{$videoId}",
            'duration' => $meta['duration'],
            'sort_order' => $maxOrder + 1,
        ]);

        $this->engine->recalculateSchedule($channel);

        // Trigger background download immediately
        $this->engine->triggerYouTubeDownload($item);

        return response()->json([
            'success' => true,
            'message' => "Added YouTube: {$meta['title']} ({$this->formatDuration($meta['duration'])}) — downloading in background",
        ]);
    }

    private function addStreamUrl(Channel $channel, string $url): JsonResponse
    {
        $exists = PlaylistItem::where('channel_id', $channel->id)
            ->where('filepath', $url)
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'error' => 'This stream URL is already in the playlist.'], 422);
        }

        // Extract duration from dur= or expire= params (googlevideo URLs use expire=unix_timestamp)
        $duration = 0.0;
        if (preg_match('/[?&]dur=(\d+(?:\.\d+)?)/', $url, $m)) {
            $duration = (float) $m[1];
        } elseif (preg_match('/[?&]expire=(\d+)/', $url, $m)) {
            // expire is an absolute unix timestamp — not a duration, so probe instead
            $duration = $this->probeDuration($url);
        }

        // Extract human-readable title from title= query param (googlevideo carries it)
        $title = 'Stream URL';
        if (preg_match('/[?&]title=([^&]+)/', $url, $m)) {
            $title = urldecode(str_replace('_', ' ', $m[1]));
            $title = substr($title, 0, 200);
        }

        $maxOrder = PlaylistItem::where('channel_id', $channel->id)->max('sort_order') ?? 0;

        PlaylistItem::create([
            'channel_id' => $channel->id,
            'title'      => $title,
            'filepath'   => $url,
            'duration'   => $duration > 0 ? $duration : 0,
            'sort_order' => $maxOrder + 1,
        ]);

        $this->engine->recalculateSchedule($channel);

        if ($this->engine->isRunning($channel)) {
            $this->engine->rebuild($channel);
        }

        return response()->json([
            'success' => true,
            'message' => "Added: {$title}" . ($duration > 0 ? " ({$this->formatDuration($duration)})" : ''),
        ]);
    }

    /**
    /**
     * Preview a URL before adding — returns title, duration, thumbnail.
     */
    public function previewUrl(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $request->validate(['url' => 'required|string|max:8000']);
        $url = trim($request->input('url'));

        // YouTube
        $videoId = YouTubeMetadataService::extractVideoId($url);
        if ($videoId !== null) {
            try {
                $meta = app(YouTubeMetadataService::class)->getVideoDetails($videoId);
                return response()->json([
                    'type'      => 'youtube',
                    'title'     => $meta['title'] ?? $videoId,
                    'duration'  => $meta['duration'] ?? 0,
                    'thumbnail' => $meta['thumbnail'] ?? "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg",
                    'playable'  => true,
                ]);
            } catch (\Throwable) {
                // No API key or API failed — return basic info from video ID
                return response()->json([
                    'type'      => 'youtube',
                    'title'     => $videoId,
                    'duration'  => 0,
                    'thumbnail' => "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg",
                    'playable'  => true,
                ]);
            }
        }

        // Direct URL — probe with ffprobe
        $duration = $this->probeDuration($url);
        $title    = $request->input('title') ?: $this->titleFromUrl($url);
        $isHls    = str_contains($url, '.m3u8') || str_contains($url, '/hls');

        return response()->json([
            'type'      => $isHls ? 'hls' : 'url',
            'title'     => $title,
            'duration'  => $duration,
            'thumbnail' => null,
            'playable'  => $duration > 0,
        ]);
    }

    /**
     * Add a URL-based video (HLS .m3u8, direct .mp4, googlevideo stream, etc.) to the playlist.
     */
    public function addUrl(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $request->validate([
            'url'   => 'required|string|max:8000',
            'title' => 'nullable|string|max:500',
        ]);

        $url = trim($request->input('url'));

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return response()->json(['success' => false, 'error' => 'URL must start with http:// or https://'], 422);
        }

        // Route googlevideo direct stream URLs through the stream-URL path
        // (they carry an expire= param so we extract duration from it)
        if (preg_match('#googlevideo\.com/videoplayback#i', $url)) {
            return $this->addStreamUrl($channel, $url);
        }

        // Also handle YouTube watch URLs pasted into the URL field
        $videoId = YouTubeMetadataService::extractVideoId($url);
        if ($videoId !== null) {
            return $this->addYouTubeById($channel, $videoId);
        }

        $title = $request->input('title') ?: $this->titleFromUrl($url);

        $exists = PlaylistItem::where('channel_id', $channel->id)
            ->where('filepath', $url)
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'error' => 'This URL is already in the playlist.'], 422);
        }

        // Probe duration via ffprobe (works for HLS .m3u8 and direct video URLs)
        $duration = $this->probeDuration($url);

        $maxOrder = PlaylistItem::where('channel_id', $channel->id)->max('sort_order') ?? 0;

        PlaylistItem::create([
            'channel_id' => $channel->id,
            'title'      => $title,
            'filepath'   => $url,
            'duration'   => $duration > 0 ? $duration : 0,
            'sort_order' => $maxOrder + 1,
        ]);

        $this->engine->recalculateSchedule($channel);

        if ($this->engine->isRunning($channel)) {
            $this->engine->rebuild($channel);
        }

        return response()->json([
            'success' => true,
            'message' => "Added: {$title}" . ($duration > 0 ? " ({$this->formatDuration($duration)})" : ''),
        ]);
    }

    /**
     * Update a playlist item's custom title and/or media group.
     */
    public function updateItemTitle(Request $request, Channel $channel, PlaylistItem $item): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $data = $request->validate([
            'custom_title' => 'nullable|string|max:500',
            'media_group'  => 'nullable|string|in:default,clean',
        ]);

        $update = ['custom_title' => $data['custom_title'] ?: null];
        if (isset($data['media_group'])) {
            $update['media_group'] = $data['media_group'];
        }
        $item->update($update);

        $this->engine->writeMetaFile($channel);

        return response()->json([
            'success' => true,
            'display_title' => $item->fresh()->display_title,
            'media_group' => $item->fresh()->media_group,
        ]);
    }

    /**
     * Download a YouTube video to local disk so it plays reliably.
     * Converts the item from youtube:ID to a local filepath.
     */
    public function downloadYouTube(Channel $channel, PlaylistItem $item): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        if (! $item->isYouTube()) {
            return response()->json(['success' => false, 'error' => 'Not a YouTube item'], 422);
        }

        $videoId = $item->youtube_id;
        $youtubeUrl = "https://www.youtube.com/watch?v={$videoId}";

        $directory = $channel->dvr_directory . '/tv_media';
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $outputFile = $directory . '/' . $videoId . '.mp4';

        // Check if already downloaded
        if (file_exists($outputFile) && filesize($outputFile) > 1024) {
            $item->update(['filepath' => $outputFile]);
            $this->engine->recalculateSchedule($channel);
            if ($this->engine->isRunning($channel)) {
                $this->engine->rebuild($channel);
            }
            return response()->json(['success' => true, 'message' => 'Video already on disk — converted to local file']);
        }

        // Try yt-dlp download with multiple clients
        $ytdlp = $this->findYtdlp();
        if ($ytdlp === null) {
            return response()->json(['success' => false, 'error' => 'yt-dlp not found on server'], 500);
        }

        $cookiePath = storage_path('app/youtube_cookies.txt');
        $proxy = \App\Models\Setting::get('youtube_proxy', '') ?: '';
        $clients = ['tv', 'tv_embedded', 'web_creator', 'ios', 'android'];

        foreach ($clients as $client) {
            $cmd = [
                $ytdlp,
                '--no-warnings',
                '-f', 'best[ext=mp4]/best',
                '--no-playlist',
                '--extractor-args', "youtube:player_client={$client}",
                '--js-runtimes', 'node',
                '-o', $outputFile,
            ];

            if (file_exists($cookiePath)) {
                $cmd[] = '--cookies';
                $cmd[] = $cookiePath;
            }

            if ($proxy !== '') {
                $cmd[] = '--proxy';
                $cmd[] = $proxy;
            }

            $cmd[] = $youtubeUrl;

            $proc = new \Symfony\Component\Process\Process($cmd);
            $proc->setTimeout(300);
            $proc->run();

            if ($proc->isSuccessful() && file_exists($outputFile) && filesize($outputFile) > 1024) {
                // Probe duration
                $duration = $this->probeDuration($outputFile);
                if ($duration > 0) {
                    $item->update([
                        'filepath' => $outputFile,
                        'duration' => $duration,
                    ]);
                    $this->engine->recalculateSchedule($channel);
                    if ($this->engine->isRunning($channel)) {
                        $this->engine->rebuild($channel);
                    }
                    return response()->json(['success' => true, 'message' => "Downloaded to disk — {$item->title}"]);
                }
            }

            @unlink($outputFile); // Clean up failed attempt
        }

        return response()->json([
            'success' => false,
            'error' => 'YouTube bot detection blocked the download. Download the video on your local machine and upload it as a media file instead.',
        ], 422);
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

        $totalDuration = $freshItems->sum('duration');
        $summary = [
            'total_duration_seconds' => $totalDuration,
            'formatted_total'        => $this->formatDurationLong((float) $totalDuration),
            'item_count'             => $freshItems->count(),
            'anchor_start'           => $freshItems->first()?->scheduled_start?->toIso8601String(),
            'end_anchor'             => $freshItems->last()?->scheduled_end?->toIso8601String(),
        ];

        return response()->json([
            'success' => true,
            'items'   => $freshItems,
            'summary' => $summary,
        ]);
    }

    /**
     * Update playlist loop count.
     */
    public function updateLoop(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $data = $request->validate([
            'playlist_loop' => 'required|integer|min:0|max:10000',
        ]);

        $this->engine->updatePlaylistLoop($channel, $data['playlist_loop']);

        return response()->json(['success' => true, 'playlist_loop' => $channel->fresh()->playlist_loop]);
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

        $totalDuration = $freshItems->sum('duration');
        $freshSummary = [
            'total_duration_seconds' => $totalDuration,
            'formatted_total'        => $this->formatDurationLong((float) $totalDuration),
            'item_count'             => $freshItems->count(),
            'anchor_start'           => $freshItems->first()?->scheduled_start?->toIso8601String(),
            'end_anchor'             => $freshItems->last()?->scheduled_end?->toIso8601String(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Playlist schedule recalculated',
            'items'   => $freshItems,
            'summary' => $freshSummary,
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
     * Update the logo position (x:y pixels).
     */
    public function updateLogoPosition(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $data = $request->validate([
            'x' => 'required|integer|min:-3840|max:3840',
            'y' => 'required|integer|min:-2160|max:2160',
        ]);

        $this->engine->updateLogoPosition($channel, "{$data['x']}:{$data['y']}");

        return response()->json(['success' => true]);
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

        // Remove old logo files on disk
        foreach (glob($directory . '/logo.*') ?: [] as $old) {
            @unlink($old);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $filepath = $directory . '/logo.' . $ext;
        $mimeType = $file->getMimeType();
        $file->move($directory, 'logo.' . $ext);

        // Create a ChannelMedia entry (updateLogo() will clean up old ones)
        $media = \App\Models\ChannelMedia::create([
            'channel_id' => $channel->id,
            'type' => 'vod',
            'name' => 'Logo',
            'filepath' => $filepath,
            'mime_type' => $mimeType,
            'filesize' => filesize($filepath),
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->engine->updateLogo($channel, $media->id);

        return response()->json(['success' => true, 'message' => 'Logo updated', 'logo_media_id' => $media->id]);
    }

    /**
     * Serve the logo image file for browser preview.
     * Logo files live outside the public directory so they need a controller route.
     */
    public function logoPreview(Channel $channel): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $dir = $channel->dvr_directory . '/cg';
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            $path = "{$dir}/logo.{$ext}";
            if (file_exists($path)) {
                $mime = match ($ext) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'webp'        => 'image/webp',
                    default       => 'image/png',
                };
                return response()->file($path, [
                    'Content-Type'  => $mime,
                    'Cache-Control' => 'no-cache, no-store',
                ]);
            }
        }

        abort(404);
    }

    /**
     * Remove the logo entirely.
     */
    public function removeLogo(Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $directory = $channel->dvr_directory . '/cg';
        foreach (glob($directory . '/logo.*') ?: [] as $old) {
            @unlink($old);
        }
        \App\Models\ChannelMedia::where('channel_id', $channel->id)
            ->where('name', 'Logo')
            ->get()->each(fn ($m) => $m->delete());

        $this->engine->updateLogo($channel, null);

        return response()->json(['success' => true]);
    }

    /**
     * Update logo scale (% of video width).
     */
    public function updateLogoScale(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $data = $request->validate(['scale' => 'required|integer|min:1|max:50']);
        $this->engine->updateLogoScale($channel, $data['scale']);

        return response()->json(['success' => true]);
    }

    /**
     * Toggle logo overlay on/off.
     */
    public function toggleLogo(Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $this->engine->toggleLogoEnabled($channel);
        $channel->refresh();

        return response()->json(['success' => true, 'logo_enabled' => (bool) $channel->logo_enabled]);
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

    private function findYtdlp(): ?string
    {
        foreach (['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        $found = trim((string) shell_exec('which yt-dlp 2>/dev/null'));

        return $found !== '' ? $found : null;
    }

    private function probeDuration(string $filepath): float
    {
        try {
            $cmd = [
                config('skymedia.ffprobe_binary', 'ffprobe'),
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
            ];

            // For remote URLs, add protocol whitelist and timeout
            if (str_starts_with($filepath, 'http://') || str_starts_with($filepath, 'https://')) {
                $cmd[] = '-protocol_whitelist';
                $cmd[] = 'file,http,https,tcp,tls,crypto';
                $cmd[] = '-rw_timeout';
                $cmd[] = '30000000'; // 30 seconds in microseconds
                $filepath = $this->encodeUrlBrackets($filepath);
            }

            $cmd[] = $filepath;

            $proc = Process::create($cmd)->setTimeout(30);
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

    /**
     * Percent-encode [ and ] in URLs — these are valid in filenames but
     * treated as range syntax by curl/ffprobe/ffmpeg's URL parser.
     * The stored filepath is kept as-is; only the path passed to tools is encoded.
     */
    private function encodeUrlBrackets(string $url): string
    {
        return str_replace(['[', ']'], ['%5B', '%5D'], $url);
    }

    /**
     * Extract a human-readable title from a URL, safely handling brackets
     * and other characters that break parse_url.
     */
    private function titleFromUrl(string $url): string
    {
        // Strip query string first, then grab the last path segment
        $noQuery = strtok($url, '?');
        $base    = basename((string) $noQuery);
        // Decode percent-encoding for display
        return $base !== '' ? urldecode($base) : $url;
    }

    private function formatDuration(float $seconds): string
    {
        $h = floor($seconds / 3600);
        $m = floor(($seconds / 60) % 60);
        $s = floor($seconds % 60);
        return $h > 0 ? "{$h}h {$m}m {$s}s" : ($m > 0 ? "{$m}m {$s}s" : "{$s}s");
    }

    private function formatDurationLong(float $seconds): string
    {
        $h  = (int) floor($seconds / 3600);
        $m  = (int) floor(($seconds / 60) % 60);
        $s  = (int) floor($seconds % 60);
        $ms = (int) round(($seconds - floor($seconds)) * 1000);
        return sprintf('%02d:%02d:%02d.%03d', $h, $m, $s, $ms);
    }

    /**
     * Update clock settings (position, size, color, timezone).
     */
    public function updateClockSettings(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $data = $request->validate([
            'position' => 'nullable|string|in:top-left,top-right,bottom-left,bottom-right',
            'fontsize' => 'required|integer|min:12|max:72',
            'color' => 'required|string|max:30',
            'format' => 'nullable|string|max:50',
            'enabled' => 'nullable|boolean',
            'x' => 'nullable|integer',
            'y' => 'nullable|integer',
            'timezone' => 'nullable|string|max:50',
        ]);

        $this->engine->updateClockSettings($channel, $data);

        return response()->json(['success' => true]);
    }

    /**
     * Update ticker settings (bg color, opacity, font size, font color, speed, position).
     */
    public function updateTickerSettings(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $data = $request->validate([
            'bg_color' => 'nullable|string|max:30',
            'bg_opacity' => 'nullable|integer|min:0|max:100',
            'font_size' => 'nullable|integer|min:10|max:72',
            'font_color' => 'nullable|string|max:30',
            'speed' => 'nullable|integer|min:10|max:500',
            'position' => 'nullable|string|in:top,center,bottom',
        ]);

        $this->engine->updateTickerSettings($channel, $data);

        return response()->json(['success' => true]);
    }

    /**
     * Update output resolution.
     */
    public function updateResolution(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $data = $request->validate([
            'resolution' => 'required|string|max:20',
        ]);

        $this->engine->updateResolution($channel, $data['resolution']);

        return response()->json(['success' => true]);
    }

    /**
     * Update NOW PLAYING / lowerthird overlay settings.
     */
    public function updateLowerthirdSettings(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $data = $request->validate([
            'position'   => 'nullable|string|in:top-left,top-right,bottom-left,bottom-right',
            'x'          => 'nullable|integer|min:-3840|max:3840',
            'y'          => 'nullable|integer|min:-2160|max:2160',
            'fontsize'   => 'nullable|integer|min:10|max:72',
            'font_color' => 'nullable|string|max:30',
            'bg_color'   => 'nullable|string|max:30',
            'bg_opacity' => 'nullable|integer|min:0|max:100',
            'enabled'    => 'nullable|boolean',
        ]);

        $this->engine->updateLowerthirdSettings($channel, $data);

        return response()->json(['success' => true]);
    }

    /**
     * Update ticker items (JSON array of {text, color, bg_color}).
     */
    public function updateTickerItems(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $data = $request->validate([
            'items'             => 'required|array|max:200',
            'items.*.text'      => 'required|string|max:500',
            'items.*.color'     => 'nullable|string|max:30',
            'items.*.bg_color'  => 'nullable|string|max:30',
            'label'             => 'nullable|string|max:200',
            'label_color'       => 'nullable|string|max:30',
            'label_bg'          => 'nullable|string|max:30',
        ]);

        // Build plain ticker_text from items for backward compat (truncated — full data is in ticker_items JSON)
        $plain = mb_substr(implode('   •   ', array_column($data['items'], 'text')), 0, 65535);

        $channel->update(array_filter([
            'ticker_items'       => $data['items'],
            'ticker_text'        => $plain,
            'ticker_label'       => $data['label'] ?? null,
            'ticker_label_color' => $data['label_color'] ?? null,
            'ticker_label_bg'    => $data['label_bg'] ?? null,
        ], fn ($v) => $v !== null));

        $this->engine->writeTickerFile($channel->fresh());

        if ($this->engine->isRunning($channel)) {
            $this->engine->rebuild($channel);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Upload a .txt or .csv file and parse it into ticker items.
     * Each non-empty line becomes one ticker item.
     * CSV format: text,color,bg_color (color columns optional).
     */
    public function uploadTickerFile(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $request->validate(['file' => 'required|file|max:512|mimes:txt,csv,plain']);

        $content = file_get_contents($request->file('file')->getRealPath());
        $lines   = preg_split('/\r?\n/', trim($content));
        $items   = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            // CSV: text,color,bg_color
            $parts = str_getcsv($line);
            $text  = trim($parts[0] ?? '');
            if ($text === '') continue;

            $items[] = [
                'text'     => substr($text, 0, 500),
                'color'    => isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null,
                'bg_color' => isset($parts[2]) && trim($parts[2]) !== '' ? trim($parts[2]) : null,
            ];
        }

        if (empty($items)) {
            return response()->json(['success' => false, 'error' => 'No valid lines found in file'], 422);
        }

        $plain = mb_substr(implode('   •   ', array_column($items, 'text')), 0, 65535);
        $channel->update(['ticker_items' => $items, 'ticker_text' => $plain]);
        $fresh = $channel->fresh();
        $this->engine->writeTickerFile($fresh);

        if ($this->engine->isRunning($fresh)) {
            $this->engine->rebuild($fresh);
        }

        return response()->json(['success' => true, 'items' => $items, 'count' => count($items)]);
    }

    public function setStreamUrl(Channel $channel, PlaylistItem $item)
    {
        $this->ensureAccess($channel);

        $url = request()->input('url', '');
        if (! str_starts_with($url, 'http')) {
            return response()->json(['error' => 'URL must start with http:// or https://'], 422);
        }

        $videoId = PlaylistItem::parseYouTubeId($item->filepath);
        if ($videoId === null) {
            return response()->json(['error' => 'Not a YouTube item'], 422);
        }

        $cacheDir = storage_path('app/youtube_cache');
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents("{$cacheDir}/{$videoId}.stream_url", $url);

        // Clean up stale download artifacts
        @unlink("{$cacheDir}/{$videoId}.downloading");
        @unlink("{$cacheDir}/{$videoId}.log");
        @unlink("{$cacheDir}/{$videoId}.sh");

        return response()->json(['success' => true, 'video_id' => $videoId]);
    }

    /**
     * Get download status for all YouTube items in the playlist.
     */
    public function downloadStatus(Channel $channel): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        $items = $channel->playlistItems()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $statuses = [];
        foreach ($items as $item) {
            if ($item->isYouTube()) {
                $statuses[$item->id] = [
                    'status' => $this->engine->getYouTubeDownloadStatus($item),
                    'video_id' => $item->youtube_id,
                ];
            }
        }

        return response()->json(['statuses' => $statuses]);
    }

    /**
     * Manually trigger a download for a YouTube item.
     */
    public function triggerDownload(Channel $channel, PlaylistItem $item): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);

        if (! $item->isYouTube()) {
            return response()->json(['success' => false, 'error' => 'Not a YouTube item'], 422);
        }

        $this->engine->triggerYouTubeDownload($item);

        return response()->json(['success' => true, 'message' => 'Download triggered']);
    }

    public function probeItem(Channel $channel, PlaylistItem $item): JsonResponse
    {
        abort_unless($channel->source_type === 'tv_playout', 404);
        $this->ensureAccess($channel);
        abort_unless($item->channel_id === $channel->id, 404);

        $path = $item->filepath;

        // YouTube: check if local file exists, otherwise report stream URL status
        if (str_starts_with($path, 'youtube:')) {
            $videoId  = \App\Models\PlaylistItem::parseYouTubeId($path);
            $cacheDir = storage_path('app/youtube_cache');
            $local    = "{$cacheDir}/{$videoId}.mp4";
            $urlCache = "{$cacheDir}/{$videoId}.stream_url";

            if (file_exists($local) && filesize($local) > 1_048_576) {
                $path = $local;
            } elseif (file_exists($urlCache)) {
                $lines = explode("\n", trim((string) file_get_contents($urlCache)));
                $path  = trim($lines[0]);
            } else {
                return response()->json([
                    'playable' => false,
                    'error'    => 'Not yet downloaded or extracted — trigger download first',
                ]);
            }
        }

        // Run ffprobe
        try {
            $cmd = [
                config('skymedia.ffprobe_binary', 'ffprobe'),
                '-v', 'error',
                '-show_entries', 'format=duration:stream=codec_name,codec_type,width,height',
                '-of', 'json',
            ];
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                array_push($cmd, '-protocol_whitelist', 'file,http,https,tcp,tls,crypto', '-rw_timeout', '10000000');
                $path = str_replace(['[', ']'], ['%5B', '%5D'], $path);
            }
            $cmd[] = $path;

            $proc = \Symfony\Component\Process\Process::create($cmd)->setTimeout(20);
            $proc->run();

            if (! $proc->isSuccessful()) {
                return response()->json([
                    'playable' => false,
                    'error'    => trim($proc->getErrorOutput()) ?: 'ffprobe failed',
                ]);
            }

            $info     = json_decode($proc->getOutput(), true);
            $duration = (float) ($info['format']['duration'] ?? 0);
            $video    = collect($info['streams'] ?? [])->firstWhere('codec_type', 'video');
            $audio    = collect($info['streams'] ?? [])->firstWhere('codec_type', 'audio');

            return response()->json([
                'playable'   => true,
                'duration'   => $duration,
                'video'      => $video ? ($video['codec_name'] . ' ' . ($video['width'] ?? '?') . 'x' . ($video['height'] ?? '?')) : null,
                'audio'      => $audio ? $audio['codec_name'] : null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['playable' => false, 'error' => $e->getMessage()]);
        }
    }

    private function ensureAccess(Channel $channel): void
    {
        $user = auth()->user();
        abort_unless($user && (($user->is_admin ?? false) || $channel->user_id === $user->id), 403);
    }
}
