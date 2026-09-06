<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HlsController extends Controller
{
    /**
     * Serve the channel's HLS output playlist or segments publicly.
     *
     * Accepts both numeric IDs (/hls/17/output.m3u8) and slugs
     * (/hls/prc-tv/output.m3u8). Numeric IDs redirect 301 to slug-based
     * URLs so nginx can serve them directly (alias requires the slug).
     *
     * When served by nginx (slug URLs), this PHP method is never called —
     * nginx resolves /hls/{slug}/{file} → /var/skymedia/dvr/{slug}/{file}
     * directly. This method only runs for numeric ID URLs (PHP fallback).
     */
    public function serve(Channel $channel, string $file): BinaryFileResponse|RedirectResponse
    {
        // Numeric ID: redirect to slug-based URL (301 permanent).
        // This keeps old embeds working while the slug URL is served by nginx.
        if (is_numeric($channel->getKey())) {
            return redirect(
                route('hls.serve', ['channel' => $channel->slug, 'file' => $file]),
                301
            );
        }

        $dvrDir = $channel->dvr_directory;

        $path = realpath("{$dvrDir}/{$file}");

        // Security: only serve files that actually live inside this channel's DVR dir
        if ($path === false || ! str_starts_with($path, realpath($dvrDir) . DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        if (! file_exists($path) || is_dir($path)) {
            abort(404);
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts'   => 'video/mp2t',
            default => 'application/octet-stream',
        };

        $headers = [
            'Content-Type'              => $mime,
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Range',
            'Accept-Ranges'             => 'bytes',
        ];

        // Cache control: playlists must never be cached (live edge detection),
        // segments can be cached briefly (they are immutable once written).
        if ($ext === 'm3u8') {
            $headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
        } else {
            $headers['Cache-Control'] = 'public, max-age=2, s-maxage=2';
        }

        return response()->file($path, $headers);
    }
}
