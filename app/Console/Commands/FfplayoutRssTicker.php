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

        // Map stable origin_id => headline text
        $headlineIds = [];
        foreach ($headlines as $title) {
            $headlineIds[$this->originId($title)] = $title;
        }

        $channelIds = array_map('intval', explode(',', $this->option('channels')));

        $added = 0;
        foreach ($channelIds as $id) {
            $dir = $this->assetDirs[$id] ?? null;
            if (!$dir || !is_dir($dir)) continue;
            $added += $this->applyHeadlines($dir, $headlineIds);
        }

        $count = count($headlines);
        $this->info("Ticker updated — {$count} headlines fetched, {$added} new lines.");
        Log::info("[FfplayoutRssTicker] Updated ticker with {$count} headlines, {$added} new lines.");

        return self::SUCCESS;
    }

    /**
     * Merge fetched headlines into a channel's overlay ticker_lines.
     *
     * Each headline becomes its own ticker line (source='rss', stable
     * origin_id) so the user can edit, disable, reorder or delete each one.
     * Manual lines (source='manual') are never touched. Deleted fetched lines
     * are remembered in rss_removed and won't come back. User-edited lines
     * (edited=true) keep their text even when the headline drops out of the feeds.
     */
    private function applyHeadlines(string $dir, array $headlineIds): int
    {
        $sidecarPath = $dir . '/overlay.json';
        $sidecar = file_exists($sidecarPath)
            ? (json_decode(file_get_contents($sidecarPath), true) ?? [])
            : [];

        $lines     = is_array($sidecar['ticker_lines'] ?? null) ? $sidecar['ticker_lines'] : [];
        $removed   = is_array($sidecar['rss_removed'] ?? null) ? $sidecar['rss_removed'] : [];
        $maxEnabled = max(1, min(20, (int) ($sidecar['rss_max_lines'] ?? 4)));

        $isRss = fn (array $l): bool => ($l['source'] ?? 'manual') === 'rss';
        $originOf = fn (array $l): string => strval($l['origin_id'] ?? '');

        // Drop the legacy blob line (id='rss') — replaced by individual lines.
        $lines = array_values(array_filter($lines, fn (array $l) => ($l['id'] ?? '') !== 'rss'));

        // Prune fetched lines that are no longer in the feeds and were not
        // user-edited. Keeps the list fresh without losing manual curation.
        $lines = array_values(array_filter($lines, function (array $l) use ($headlineIds) {
            if (($l['source'] ?? 'manual') !== 'rss') return true;
            if (! empty($l['edited'])) return true;
            return isset($headlineIds[$l['origin_id'] ?? '']);
        }));

        $byset  = [];
        $enabledRss = 0;
        foreach ($lines as $i => $line) {
            if ($isRss($line)) {
                $byset[$originOf($line)] = $i;
                if (! empty($line['enabled'])) $enabledRss++;
            }
        }

        $now   = date('c');
        $added = 0;

        foreach ($headlineIds as $originId => $title) {
            if (in_array($originId, $removed, true)) continue;
            if (isset($byset[$originId])) continue;

            $enabled = $enabledRss < $maxEnabled;
            $lines[] = [
                'id'             => 'rss_' . $originId,
                'enabled'        => $enabled,
                'source'         => 'rss',
                'origin_id'      => $originId,
                'fetched_at'     => $now,
                'text'           => $title,
                'text_color'     => '#fcd116',
                'bg_color'       => '#000000',
                'bg_opacity'     => 0.85,
                'font_size'      => 22,
                'label_text'     => '',
                'label_color'    => '#ffffff',
                'label_bg_color' => '#c0392b',
                'label_image'    => '',
            ];
            if ($enabled) $enabledRss++;
            $added++;
        }

        // Legacy flat ticker text (falls back to this only when no lines render).
        $tickerText = implode('   •   ', array_map(
            fn (array $l) => trim(strval($l['text'] ?? '')),
            array_filter($lines, fn (array $l) => trim(strval($l['text'] ?? '')) !== '')
        ));

        $sidecar['ticker_lines'] = $lines;
        $sidecar['ticker_text']  = $tickerText;
        $sidecar['rss_removed']  = array_values(array_unique(array_map('strval', $removed)));

        file_put_contents($sidecarPath, json_encode($sidecar));

        // Write legacy ticker.txt + per-line reload files.
        file_put_contents($dir . '/ticker.txt', $tickerText);
        @chmod($dir . '/ticker.txt', 0664);
        foreach ($lines as $line) {
            if (empty($line['id'])) continue;
            $lineFile = $dir . '/ticker_line_' . $line['id'] . '.txt';
            file_put_contents($lineFile, strval($line['text'] ?? ' '));
            @chmod($lineFile, 0664);
        }

        return $added;
    }

    private function originId(string $title): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $title) ?? ''));
        return substr(sha1($normalized), 0, 12);
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
