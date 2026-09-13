<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches Cameroon & African news from public RSS feeds and writes the
 * scrolling ticker text to both ffplayout channel asset directories.
 * Runs every 15 minutes via the scheduler.
 */
class FfplayoutRssTicker extends Command
{
    protected $signature   = 'ffplayout:rss-ticker {--channels=1,2}';
    protected $description = 'Fetch Cameroon/Africa RSS news and update ffplayout ticker text files';

    /** RSS feeds — Cameroon-first, then pan-African */
    private array $feeds = [
        // Cameroon (working feeds)
        'https://www.cameroon-info.net/rss.xml',
        'https://www.237actu.com/feed/',
        'https://www.camerounweb.com/rss/actualite.xml',
        // Pan-African
        'https://www.africanews.com/feed/rss',
        'https://allafrica.com/tools/headlines/rdf/africa/headlines.rdf',
        'https://www.theafricareport.com/feed/',
        'https://feeds.bbci.co.uk/news/world/africa/rss.xml',
    ];

    /** Asset dirs keyed by channel id */
    private array $assetDirs = [
        1 => '/home/ffpu/media/00-assets',
        2 => '/home/ffpu/media/2/00-assets',
    ];

    public function handle(): int
    {
        $headlines = $this->fetchHeadlines();

        if (empty($headlines)) {
            $this->warn('No headlines fetched — ticker unchanged.');
            return self::SUCCESS;
        }

        $ticker = implode('   •   ', $headlines);

        $channelIds = array_map('intval', explode(',', $this->option('channels')));

        foreach ($channelIds as $id) {
            $dir  = $this->assetDirs[$id] ?? null;
            if (!$dir) continue;

            $file = $dir . '/ticker.txt';
            if (!is_dir($dir)) continue;

            file_put_contents($file, $ticker);
            @chmod($file, 0664);

            // Update sidecar JSON — ticker_text + ticker_lines[0] (RSS line)
            $sidecarPath = $dir . '/overlay.json';
            $sidecar = file_exists($sidecarPath)
                ? (json_decode(file_get_contents($sidecarPath), true) ?? [])
                : [];
            $sidecar['ticker_text'] = $ticker;

            // Keep ticker_lines[0] (id='rss') in sync
            if (!isset($sidecar['ticker_lines'])) $sidecar['ticker_lines'] = [];
            $rssIdx = null;
            foreach ($sidecar['ticker_lines'] as $i => $line) {
                if (($line['id'] ?? '') === 'rss') { $rssIdx = $i; break; }
            }
            if ($rssIdx !== null) {
                $sidecar['ticker_lines'][$rssIdx]['text'] = $ticker;
                // Also update the per-line text file for live reload
                $lineFile = $dir . '/ticker_line_rss.txt';
                file_put_contents($lineFile, $ticker);
                @chmod($lineFile, 0664);
            }

            file_put_contents($sidecarPath, json_encode($sidecar));
        }

        $count = count($headlines);
        $this->info("Ticker updated — {$count} headlines.");
        Log::info("[FfplayoutRssTicker] Updated ticker with {$count} headlines.");

        return self::SUCCESS;
    }

    private function fetchHeadlines(): array
    {
        $headlines = [];

        foreach ($this->feeds as $url) {
            try {
                $response = Http::timeout(8)->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SkyMedia/1.0)',
                ])->get($url);

                if (!$response->successful()) continue;

                $body = $response->body();

                // Only convert if feed explicitly declares a non-UTF-8 encoding
                if (preg_match('/encoding=["\']([^"\']+)["\']/', $body, $m)) {
                    $declared = strtoupper(trim($m[1]));
                    if (!in_array($declared, ['UTF-8', 'UTF8'])) {
                        $converted = @mb_convert_encoding($body, 'UTF-8', $declared);
                        if ($converted !== false) {
                            $body = preg_replace('/encoding=["\'][^"\']*["\']/', 'encoding="UTF-8"', $converted);
                        }
                    }
                }

                $xml = @simplexml_load_string($body);
                if (!$xml) continue;

                // RSS 2.0
                $items = $xml->channel->item ?? [];
                // Atom
                if (empty($items)) $items = $xml->entry ?? [];

                $count = 0;
                foreach ($items as $item) {
                    $title = trim((string) ($item->title ?? ''));
                    if ($title === '' || strlen($title) < 10) continue;
                    // Strip HTML tags that sometimes appear in titles
                    $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $headlines[] = $title;
                    if (++$count >= 4) break; // max 4 per feed
                }
            } catch (\Throwable $e) {
                Log::debug("[FfplayoutRssTicker] Feed {$url} failed: {$e->getMessage()}");
            }
        }

        // Deduplicate and cap at 20 headlines
        $headlines = array_values(array_unique($headlines));
        return array_slice($headlines, 0, 20);
    }
}
