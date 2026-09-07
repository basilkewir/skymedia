<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ProxyService — fetches working SOCKS5 proxies from the proxifly
 * free-proxy-list repository and caches one for yt-dlp to use.
 *
 * Source: https://github.com/proxifly/free-proxy-list
 */
class ProxyService
{
    private const LIST_URL     = 'https://raw.githubusercontent.com/proxifly/free-proxy-list/main/proxies/protocols/socks5/data.json';
    private const CACHE_KEY    = 'youtube_working_proxy';
    private const CACHE_TTL    = 1800; // 30 min
    private const TEST_TIMEOUT = 8;

    /**
     * Return a working socks5 proxy string (e.g. "socks5://1.2.3.4:1080") or null.
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
            Log::warning('[ProxyService] Could not fetch SOCKS5 proxy list');
            return null;
        }

        shuffle($proxies);

        foreach (array_slice($proxies, 0, 60) as $proxy) {
            if ($this->test($proxy)) {
                Cache::put(self::CACHE_KEY, $proxy, self::CACHE_TTL);
                Log::info("[ProxyService] Working SOCKS5 proxy found: {$proxy}");
                return $proxy;
            }
        }

        Log::warning('[ProxyService] No working SOCKS5 proxy found in tested batch');
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
                $ip   = $entry['ip']   ?? ($entry['host'] ?? null);
                $port = $entry['port'] ?? null;
                if ($ip && $port) {
                    $proxies[] = "socks5://{$ip}:{$port}";
                }
            }

            return $proxies;
        } catch (\Throwable $e) {
            Log::warning("[ProxyService] Fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Test a SOCKS5 proxy by fetching youtube.com/robots.txt through it via curl.
     * PHP's Http client (Guzzle) supports socks5:// proxies via curl.
     */
    private function test(string $proxy): bool
    {
        try {
            $response = Http::timeout(self::TEST_TIMEOUT)
                ->withOptions([
                    'proxy'  => $proxy,
                    'verify' => false,
                ])
                ->get('https://www.youtube.com/robots.txt');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
