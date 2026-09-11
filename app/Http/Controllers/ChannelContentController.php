<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadMediaToMp4;
use App\Models\Channel;
use App\Models\ChannelMedia;
use App\Services\PlayoutService;
use App\Services\PushService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Process\Process;

class ChannelContentController extends Controller
{
    public function __construct(protected PlayoutService $playout, protected PushService $push) {}

    public function index(Channel $channel): Response
    {
        $this->access($channel);
        $channel->load(['media', 'logoMedia']);
        return Inertia::render('Channels/Content', [
            'channel' => $channel,
            'previewUrl' => $channel->logo_media_id || $channel->ticker_enabled
                ? $this->brandedPreviewUrl($channel)
                : route('hls.serve', [$channel, 'output.m3u8']),
            'serverLogoPreviewUrl' => $channel->logoMedia
                ? route('hls.serve', [$channel, 'content/' . basename($channel->logoMedia->filepath)])
                : null,
        ]);
    }

    public function upload(Request $request, Channel $channel): RedirectResponse
    {
        $this->access($channel);
        $data = $request->validate([
            'type' => 'required|in:vod,logo',
            'file' => 'required|file|max:2097152',
        ]);
        $file = $request->file('file');
        $fileSize = $file->getSize();
        // UploadedFile points to PHP's temporary upload path. Capture all
        // metadata before move(), because that temporary path no longer exists
        // after the file has been moved into the channel content directory.
        $mimeType = (string) $file->getMimeType();
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension() ?: ($data['type'] === 'logo' ? 'png' : 'mp4'));
        if ($data['type'] === 'vod' && ! str_starts_with($mimeType, 'video/')) abort(422, 'Please upload a video file.');
        if ($data['type'] === 'logo' && ! str_starts_with($mimeType, 'image/')) abort(422, 'Please upload an image file.');

        if ($channel->hasStorageQuota() && ! $channel->canStore($fileSize)) {
            $available = $channel->storage_quota_bytes - $channel->storage_used_bytes;
            return back()->withErrors(['file' => 'Upload exceeds channel storage quota. Available: ' . $this->formatBytes($available)]);
        }

        $dir = $channel->dvr_directory . '/content';
        if (! is_dir($dir)) mkdir($dir, 0755, true);
        $name = Str::uuid() . '.' . $extension;
        $file->move($dir, $name);
        $media = $channel->media()->create([
            'type' => $data['type'], 'name' => $originalName,
            'filepath' => $dir . '/' . $name, 'mime_type' => $mimeType,
            'filesize' => $fileSize,
            'sort_order' => (int) $channel->media()->max('sort_order') + 1,
        ]);

        if ($fileSize > 0) {
            $channel->increment('storage_used_bytes', $fileSize);
        }
        if ($data['type'] === 'logo' && ! $channel->logo_media_id) {
            $channel->update(['logo_media_id' => $media->id]);
            // Immediately restart the push so the logo filter is applied
            if ($this->push->isRunning($channel->fresh())) {
                $this->push->stop($channel->fresh());
                $this->push->start($channel->fresh());
            }
        }
        if ($data['type'] === 'vod' && $channel->playout_status === 'fallback') {
            $this->playout->switchToFallback($channel->fresh());
        }
        return back()->with('success', strtoupper($data['type']) . ' uploaded');
    }

    /**
     * Preview a remote media URL (HLS .m3u8, direct MP4, etc.) by probing
     * it with ffprobe.  Returns the inferred title, duration and whether the
     * stream is reachable.
     */
    public function previewUrl(Request $request, Channel $channel): JsonResponse
    {
        $this->access($channel);

        $request->validate(['url' => 'required|string|max:8000']);
        $url = trim($request->input('url'));

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return response()->json(['success' => false, 'error' => 'URL must start with http:// or https://'], 422);
        }

        $duration = $this->probeUrl($url);
        $title    = $request->input('title') ?: $this->titleFromUrl($url);
        $isHls    = str_contains($url, '.m3u8') || str_contains($url, '/hls');

        return response()->json([
            'success'  => true,
            'title'    => $title,
            'duration' => $duration,
            'playable' => $duration > 0,
            'type'     => $isHls ? 'hls' : 'url',
        ]);
    }

    /**
     * Queue a URL download + MP4 transcode into the channel's content library.
     */
    public function downloadFromUrl(Request $request, Channel $channel): JsonResponse
    {
        $this->access($channel);

        $request->validate([
            'url'   => 'required|string|max:8000',
            'title' => 'nullable|string|max:500',
        ]);

        $url = trim($request->input('url'));

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return response()->json(['success' => false, 'error' => 'URL must start with http:// or https://'], 422);
        }

        // Prevent duplicate downloads of the same URL.
        $exists = $channel->media()
            ->where('filepath', $url)
            ->where('type', 'vod')
            ->exists();
        if ($exists) {
            return response()->json(['success' => false, 'error' => 'This URL is already being downloaded or has already been added.'], 422);
        }

        // Basic quota guard.
        if ($channel->hasStorageQuota()
            && $channel->storage_used_bytes >= $channel->storage_quota_bytes) {
            return response()->json(['success' => false, 'error' => 'Channel storage quota is full. Remove some media to free up space.'], 422);
        }

        $title = $request->input('title') ?: $this->titleFromUrl($url);

        $dir = $channel->dvr_directory . '/content';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $safeName   = preg_replace('/[^a-zA-Z0-9._-]/', '_', $title);
        $safeName   = rtrim(substr($safeName, 0, 100), '._');
        $outputPath = $dir . '/' . time() . '_' . $safeName . '.mp4';

        $maxOrder = (int) $channel->media()->max('sort_order') + 1;

        $media = $channel->media()->create([
            'type'       => 'vod',
            'name'       => $title,
            'filepath'   => $url,           // temporary — job updates to local path
            'mime_type'  => 'application/x-downloading',
            'filesize'   => 0,
            'sort_order' => $maxOrder,
            'is_active'  => false,          // activated once download completes
        ]);

        DownloadMediaToMp4::dispatch($media->id, $url, $outputPath);

        Log::info("[ContentDownload] Media {$media->id}: queued URL download → {$outputPath}");

        return response()->json([
            'success'  => true,
            'message'  => "Downloading and converting to MP4: {$title}",
            'media_id' => $media->id,
        ]);
    }

    /**
     * Return the download status for every VOD being fetched from a URL.
     *
     *   "downloading" — lock file exists (job is actively running)
     *   "queued"      — no lock file yet (job not yet picked up)
     *   "ready"       — local file exists on disk
     */
    public function downloadStatus(Channel $channel): JsonResponse
    {
        $this->access($channel);

        $statuses = [];
        foreach ($channel->media as $media) {
            if (str_starts_with($media->filepath, 'http://') || str_starts_with($media->filepath, 'https://')) {
                $lockFile = $channel->dvr_directory . '/content/download_' . $media->id . '.lock';
                $statuses[$media->id] = file_exists($lockFile) ? 'downloading' : 'queued';
            } elseif (file_exists($media->filepath)) {
                $statuses[$media->id] = 'ready';
            }
        }

        return response()->json(['statuses' => $statuses]);
    }

    /**
     * Probe a remote URL for duration via ffprobe.
     * Uses protocol_whitelist so HLS playlists resolve, plus browser headers
     * for CDN compatibility.
     */
    private function probeUrl(string $url): float
    {
        try {
            $cmd = [
                config('skymedia.ffprobe_binary', 'ffprobe'),
                '-v', 'quiet',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
            ];

            if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                $cmd[] = '-protocol_whitelist';
                $cmd[] = 'file,http,https,tcp,tls,crypto';
                $cmd[] = '-rw_timeout';
                $cmd[] = '30000000'; // 30 s

                $ua = trim((string) config('skymedia.http_user_agent', ''));
                if ($ua !== '') {
                    $cmd[] = '-user_agent';
                    $cmd[] = $ua;
                }

                if (str_starts_with(strtolower($url), 'https://')
                    && config('skymedia.hls_tls_verify', false) === false) {
                    $cmd[] = '-tls_verify';
                    $cmd[] = '0';
                }

                $url = str_replace(['[', ']'], ['%5B', '%5D'], $url);
            }

            $cmd[] = $url;

            $proc = new Process($cmd);
            $proc->setTimeout(30);
            $proc->run();

            $out = trim($proc->getOutput());
            if ($proc->isSuccessful() && is_numeric($out)) {
                return (float) $out;
            }
        } catch (\Throwable) {
            // ignore — caller treats 0.0 as "not playable"
        }

        return 0.0;
    }

    /**
     * Extract a human-readable title from a URL.
     */
    private function titleFromUrl(string $url): string
    {
        $noQuery = strtok($url, '?');
        $base    = basename((string) $noQuery);

        return $base !== '' ? urldecode($base) : $url;
    }

    public function update(Request $request, Channel $channel): RedirectResponse
    {
        $this->access($channel);
        $data = $request->validate([
            'playlist' => 'array', 'playlist.*.id' => 'required|integer', 'playlist.*.is_active' => 'required|boolean',
            'logo_media_id' => 'nullable|integer', 'logo_position' => ['required', 'regex:/^(top-left|top-right|bottom-left|bottom-right|\d+:\d+)$/'],
            'ticker_enabled' => 'required|boolean', 'ticker_text' => 'nullable|string|max:500',
        ]);
        $brandingChanged = (int) $channel->logo_media_id !== (int) ($data['logo_media_id'] ?? 0)
            || $channel->logo_position !== $data['logo_position']
            || (bool) $channel->ticker_enabled !== (bool) $data['ticker_enabled']
            || (string) $channel->ticker_text !== (string) ($data['ticker_text'] ?? '');
        foreach ($data['playlist'] ?? [] as $order => $item) {
            $channel->media()->whereKey($item['id'])->where('type', 'vod')->update(['sort_order' => $order, 'is_active' => $item['is_active']]);
        }
        $logoId = $data['logo_media_id'] ?? null;
        if ($logoId && ! $channel->media()->whereKey($logoId)->where('type', 'logo')->exists()) abort(422);
        $channel->update(['logo_media_id' => $logoId, 'logo_position' => $data['logo_position'], 'ticker_enabled' => $data['ticker_enabled'], 'ticker_text' => $data['ticker_text']]);
        $channel->refresh();
        if ($channel->playout_status === 'fallback') $this->playout->switchToFallback($channel);
        if ($brandingChanged && $this->push->isRunning($channel)) {
            $this->push->stop($channel);
            $this->push->start($channel->fresh());
        }
        return back()->with('success', 'Playlist and branding saved');
    }

    public function destroy(Channel $channel, ChannelMedia $media): RedirectResponse
    {
        $this->access($channel);
        abort_unless($media->channel_id === $channel->id, 404);
        $wasVod = $media->type === 'vod';
        $wasLogo = $media->type === 'logo' && (int) $channel->logo_media_id === (int) $media->id;
        if ($wasVod) $media->update(['is_active' => false]);
        if ($wasVod && $channel->playout_status === 'fallback') {
            // Warm and publish the replacement playlist while the old media is
            // still readable by the retiring fallback process.
            if (! $this->playout->switchToFallback($channel->fresh())) {
                $media->update(['is_active' => true]);
                return back()->withErrors(['media' => 'The replacement fallback could not be prepared. The existing VOD was kept on air.']);
            }
        }
        $size = filesize($media->filepath) ?: 0;
        @unlink($media->filepath);
        $media->delete();
        if ($size > 0) {
            $channel->decrement('storage_used_bytes', $size);
        }
        if ($wasLogo && $this->push->isRunning($channel)) {
            $this->push->stop($channel);
            $this->push->start($channel->fresh());
        }
        return back()->with('success', 'Media removed');
    }

    private function brandedPreviewUrl(Channel $channel): string
    {
        $host = config('skymedia.server_ip');
        if ($host === 'localhost') {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        }
        return "http://{$host}:8081/hls-static/{$channel->slug}/index.m3u8";
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return sprintf('%.2f %s', $bytes, $units[$i]);
    }

    private function access(Channel $channel): void
    {
        $user = auth()->user();
        abort_unless($user && (($user->is_admin ?? false) || $channel->user_id === $user->id), 403);
    }
}
