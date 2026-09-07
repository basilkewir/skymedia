<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ProxyService — fetches a fresh working HTTP proxy from the proxifly
 * free-proxy-list repository and caches it for yt-dlp to use.
 *
 * Source: https://github.com/proxifly/free-proxy-list
 */
class ProxyService
{
    private const LIST_URL    = 'https://raw.githubusercontent.com/proxifly/free-proxy-list/main/proxies/protocols/http/data.json';
    private const CACHE_KEY   = 'youtube_working_proxy';
    private const POOL_KEY    = 'youtube_proxy_pool';
    private const CACHE_TTL   = 1800; // 30 min
    private const TEST_URL    = 'https://www.youtube.com/robots.txt';
    private const TEST_TIMEOUT = 8;

    /**
     * Return a working proxy string (e.g. "http://1.2.3.4:8080") or null.
     * Uses the cached value when available; refreshes automatically on miss.
     */
    public function getWorkingProxy(): ?string
    {
        $proxy = Cache::get(self::CACHE_KEY);
        if ($proxy !== null) {
            return $proxy;
        }

        return $this->refresh();
    }

    /**
     * Force-fetch a fresh proxy list, find a working proxy, cache and return it.
     */
    public function refresh(): ?string
    {
        $proxies = $this->fetchList();
        if (empty($proxies)) {
            Log::warning('[ProxyService] Could not fetch proxy list');
            return null;
        }

        // Shuffle so we don't always hammer the same proxies
        shuffle($proxies);

        foreach (array_slice($proxies, 0, 40) as $proxy) {
            if ($this->test($proxy)) {
                Cache::put(self::CACHE_KEY, $proxy, self::CACHE_TTL);
                Log::info("[ProxyService] Working proxy found: {$proxy}");
                return $proxy;
            }
        }

        Log::warning('[ProxyService] No working proxy found in tested batch');
        Cache::forget(self::CACHE_KEY);
        return null;
    }

    /**
     * Invalidate the cached proxy (call when yt-dlp fails with the current one).
     */
    public function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ─────────────────────────────────────────────────────────────────

    private function fetchList(): array
    {
        try {
            $response = Http::timeout(15)->get(self::LIST_URL);
            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            if (! is_array($data)) {
                return [];
            }

            $proxies = [];
            foreach ($data as $entry) {
                // Each entry: { "ip": "1.2.3.4", "port": 8080, "protocols": ["http"], ... }
                $ip   = $entry['ip']   ?? ($entry['host'] ?? null);
                $port = $entry['port'] ?? null;
                if ($ip && $port) {
                    $proxies[] = "http://{$ip}:{$port}";
                }
            }

            return $proxies;
        } catch (\Throwable $e) {
            Log::warning("[ProxyService] Fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    private function test(string $proxy): bool
    {
        try {
            $response = Http::timeout(self::TEST_TIMEOUT)
                ->withOptions(['proxy' => $proxy, 'verify' => false])
                ->get(self::TEST_URL);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
