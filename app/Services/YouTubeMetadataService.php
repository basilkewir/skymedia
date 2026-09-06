<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YouTubeMetadataService — extracts video metadata via YouTube Data API v3.
 *
 * Uses Guzzle (already in the project) instead of the heavy google/apiclient.
 * No bot detection issues because this is a legitimate API call.
 */
class YouTubeMetadataService
{
    private const API_BASE = 'https://www.googleapis.com/youtube/v3';

    /**
     * Extract video ID from various YouTube URL formats.
     */
    public static function extractVideoId(string $url): ?string
    {
        $patterns = [
            '#(?:youtube\.com/(?:watch\?.*?v=|embed/|v/|shorts/)|youtu\.be/)([a-zA-Z0-9_-]{11})#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Fetch video metadata from YouTube Data API v3.
     *
     * @return array{title: string, duration: float, thumbnail: string, channel: string}
     *
     * @throws \RuntimeException
     */
    public function getVideoDetails(string $videoId): array
    {
        $apiKey = Setting::get('youtube_api_key') ?: config('skymedia.youtube_api_key', '');
        if (empty($apiKey)) {
            throw new \RuntimeException('YouTube API key not configured. Set YOUTUBE_API_KEY in .env');
        }

        $response = Http::timeout(10)->get(self::API_BASE . '/videos', [
            'part' => 'snippet,contentDetails',
            'id' => $videoId,
            'key' => $apiKey,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException("YouTube API error: HTTP {$response->status()}");
        }

        $data = $response->json();
        $items = $data['items'] ?? [];

        if (empty($items)) {
            throw new \RuntimeException("YouTube video not found: {$videoId}");
        }

        $item = $items[0];
        $snippet = $item['snippet'] ?? [];
        $contentDetails = $item['contentDetails'] ?? [];

        // Parse ISO 8601 duration (e.g., PT1H2M3S -> 3723 seconds)
        $durationStr = $contentDetails['duration'] ?? 'PT0S';
        $duration = $this->parseIso8601Duration($durationStr);

        return [
            'title' => $snippet['title'] ?? 'Untitled',
            'duration' => $duration,
            'thumbnail' => $snippet['thumbnails']['high']['url']
                ?? $snippet['thumbnails']['default']['url']
                ?? '',
            'channel' => $snippet['channelTitle'] ?? '',
        ];
    }

    /**
     * Parse ISO 8601 duration string (PT#H#M#S) to seconds.
     */
    private function parseIso8601Duration(string $duration): float
    {
        $seconds = 0.0;

        if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/i', $duration, $matches)) {
            $seconds += (int) ($matches[1] ?? 0) * 3600;
            $seconds += (int) ($matches[2] ?? 0) * 60;
            $seconds += (int) ($matches[3] ?? 0);
        }

        return $seconds;
    }
}
