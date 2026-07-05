<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\ChannelMedia;
use App\Services\PlayoutService;
use App\Services\PushService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

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
        // UploadedFile points to PHP's temporary upload path. Capture all
        // metadata before move(), because that temporary path no longer exists
        // after the file has been moved into the channel content directory.
        $mimeType = (string) $file->getMimeType();
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension() ?: ($data['type'] === 'logo' ? 'png' : 'mp4'));
        if ($data['type'] === 'vod' && ! str_starts_with($mimeType, 'video/')) abort(422, 'Please upload a video file.');
        if ($data['type'] === 'logo' && ! str_starts_with($mimeType, 'image/')) abort(422, 'Please upload an image file.');
        $dir = $channel->dvr_directory . '/content';
        if (! is_dir($dir)) mkdir($dir, 0755, true);
        $name = Str::uuid() . '.' . $extension;
        $file->move($dir, $name);
        $media = $channel->media()->create([
            'type' => $data['type'], 'name' => $originalName,
            'filepath' => $dir . '/' . $name, 'mime_type' => $mimeType,
            'filesize' => filesize($dir . '/' . $name),
            'sort_order' => (int) $channel->media()->max('sort_order') + 1,
        ]);
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

    public function update(Request $request, Channel $channel): RedirectResponse
    {
        $this->access($channel);
        $data = $request->validate([
            'playlist' => 'array', 'playlist.*.id' => 'required|integer', 'playlist.*.is_active' => 'required|boolean',
            'logo_media_id' => 'nullable|integer', 'logo_position' => 'required|in:top-left,top-right,bottom-left,bottom-right',
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
        @unlink($media->filepath);
        $media->delete();
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

    private function access(Channel $channel): void
    {
        $user = auth()->user();
        abort_unless($user && (($user->is_admin ?? false) || $channel->user_id === $user->id), 403);
    }
}
