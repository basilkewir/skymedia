<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HlsController extends Controller
{
    /**
     * Serve the channel's HLS output playlist or segments publicly.
     *
     * The output.m3u8 symlink (and the segments it references) live in the
     * channel's DVR directory. This endpoint exposes them with the correct
     * HLS MIME types and CORS headers so they can be played directly in
     * browsers / players.
     */
    public function serve(Channel $channel, string $file): BinaryFileResponse
    {
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
            'ts' => 'video/mp2t',
            default => 'application/octet-stream',
        };

        $headers = [
            'Content-Type' => $mime,
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => $ext === 'm3u8' ? 'no-cache' : 'public, max-age=5',
            'Accept-Ranges' => 'bytes',
        ];

        return response()->file($path, $headers);
    }
}
