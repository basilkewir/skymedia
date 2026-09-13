<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reads ffplayout's HLS output, burns ticker + title drawtext overlays,
 * and pushes to the configured RTMP destination.
 *
 * Uses /usr/bin/ffmpeg (system build — has libfreetype/drawtext).
 * ffplayout's /usr/local/bin/ffmpeg does NOT have drawtext.
 *
 * Push URL is stored in the channel's overlay.json sidecar as relay_push_url.
 */
class FfplayoutOverlayRelay extends Command
{
    protected $signature   = 'ffplayout:overlay-relay {--channel=1}';
    protected $description = 'Run ffmpeg overlay relay for an ffplayout channel (drawtext ticker/title → RTMP push)';

    private string $ffmpeg    = '/usr/bin/ffmpeg';
    private string $font      = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    private string $baseMedia = '/home/ffpu/media';

    private array $hlsSources = [
        1 => '/home/ffpu/public/live/stream.m3u8',
        2 => '/home/ffpu/public/2/live/stream.m3u8',
    ];

    public function handle(): int
    {
        $channelId = (int) $this->option('channel');
        $hlsSource = $this->hlsSources[$channelId] ?? null;

        if (!$hlsSource) {
            $this->error("Unknown channel: {$channelId}");
            return self::FAILURE;
        }

        $assetsDir = $channelId === 1
            ? $this->baseMedia . '/00-assets'
            : $this->baseMedia . "/{$channelId}/00-assets";

        $overlay = $this->readOverlay($assetsDir);
        $pushUrl = trim($overlay['relay_push_url'] ?? '');

        if ($pushUrl === '') {
            $this->error("No relay_push_url set for channel {$channelId}. Configure it in the Overlays UI.");
            return self::FAILURE;
        }

        // Sync per-line text files from overlay.json so they exist before ffmpeg starts
        $this->syncLineFiles($overlay, $assetsDir);

        $vf  = $this->buildVf($overlay, $assetsDir);
        $cmd = $this->buildCommand($hlsSource, $vf, $pushUrl);

        $this->info("ch{$channelId} relay starting → {$pushUrl}");
        Log::info("[FfplayoutOverlayRelay] ch{$channelId} starting → {$pushUrl}");

        $pidFile = "/tmp/ffplayout_relay_{$channelId}.pid";

        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($proc)) {
            $this->error('Failed to start ffmpeg');
            return self::FAILURE;
        }

        $status = proc_get_status($proc);
        file_put_contents($pidFile, $status['pid']);
        fclose($pipes[0]);

        $lastTitleSync = 0;

        while (proc_get_status($proc)['running']) {
            if (time() - $lastTitleSync >= 5) {
                try {
                    $this->syncNowPlayingTitle($channelId, $assetsDir);
                } catch (\Throwable $e) {
                    Log::warning("[FfplayoutOverlayRelay] ch{$channelId} now-playing sync failed: {$e->getMessage()}");
                }
                $lastTitleSync = time();
            }
            sleep(2);
        }

        $exit = proc_close($proc);
        @unlink($pidFile);
        Log::warning("[FfplayoutOverlayRelay] ch{$channelId} exited {$exit}");

        return $exit === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function buildVf(array $overlay, string $assetsDir): string
    {
        $lines = $overlay['ticker_lines'] ?? [];

        // Legacy fallback: if no ticker_lines, use old flat ticker/title fields
        if (empty($lines)) {
            return $this->buildVfLegacy($overlay, $assetsDir);
        }

        $filters = [];
        $yOffset = 0; // stacks from bottom up

        // Render lines in reverse so line[0] is at the bottom
        foreach (array_reverse($lines) as $line) {
            if (empty($line['enabled'])) continue;

            $size      = max(12, (int) ($line['font_size'] ?? 22));
            $barH      = $size + 20;
            $color     = $this->sanitizeColor($line['text_color'] ?? '#fcd116');
            $bgHex     = $this->colorToHex($line['bg_color'] ?? '#000000');
            $bgOpacity = number_format(min(1.0, max(0.0, (float) ($line['bg_opacity'] ?? 0.75))), 2, '.', '');
            $yPos      = "h-{$barH}-{$yOffset}+10";

            // Write text to a per-line file for live reload
            $lineFile = $assetsDir . '/ticker_line_' . ($line['id'] ?? md5(json_encode($line))) . '.txt';
            if (!file_exists($lineFile)) {
                file_put_contents($lineFile, $line['text'] ?? ' ');
                @chmod($lineFile, 0664);
            }

            // Background box for the full bar
            $filters[] = "drawbox=x=0:y=h-{$barH}-{$yOffset}:w=iw:h={$barH}:color={$bgHex}@{$bgOpacity}:t=fill";

            // Optional label on the left
            $labelWidth = 0;
            if (!empty($line['label_text'])) {
                $labelColor   = $this->sanitizeColor($line['label_color'] ?? '#ffffff');
                $labelBgHex   = $this->colorToHex($line['label_bg_color'] ?? '#c0392b');
                $labelSize    = max(12, (int) ($size * 0.9));
                $labelPad     = 10;
                $labelWidth   = (int) (strlen($line['label_text']) * $labelSize * 0.65 + $labelPad * 2);

                // Label background pill
                $filters[] = "drawbox=x=0:y=h-{$barH}-{$yOffset}:w={$labelWidth}:h={$barH}:color={$labelBgHex}@1.0:t=fill";

                // Label text
                $labelY = "h-{$barH}-{$yOffset}+" . (int)(($barH - $labelSize) / 2);
                $filters[] = "drawtext=fontfile={$this->font}"
                    . ":text=" . $this->escapeDrawtext($line['label_text'])
                    . ":fontsize={$labelSize}:fontcolor={$labelColor}"
                    . ":x={$labelPad}:y={$labelY}"
                    . ":shadowcolor=black:shadowx=1:shadowy=1";
            }

            // Label image (if set and file exists)
            if (!empty($line['label_image'])) {
                $imgPath = $assetsDir . '/ticker_labels/' . basename($line['label_image']);
                if (file_exists($imgPath)) {
                    $imgH      = $barH - 8;
                    $imgY      = "h-{$barH}-{$yOffset}+4";
                    $filters[] = "movie={$imgPath},scale=-1:{$imgH}[lbl{$yOffset}];[in][lbl{$yOffset}]overlay=4:{$imgY}[in]";
                    $labelWidth = max($labelWidth, $imgH + 8);
                }
            }

            // Scrolling ticker text
            $textX = $labelWidth > 0 ? "w-mod(max(t*110\\,0)\\,w+tw)+{$labelWidth}" : "w-mod(max(t*110\\,0)\\,w+tw)";
            $filters[] = "drawtext=fontfile={$this->font}:textfile={$lineFile}:reload=1"
                . ":fontsize={$size}:fontcolor={$color}"
                . ":x={$textX}:y={$yPos}"
                . ":shadowcolor=black:shadowx=1:shadowy=1";

            $yOffset += $barH;
        }

        return empty($filters) ? 'null' : implode(',', $filters);
    }

    private function buildVfLegacy(array $overlay, string $assetsDir): string
    {
        $tickerOn = !empty($overlay['ticker_enabled']);
        $titleOn  = !empty($overlay['title_enabled']);

        if (!$tickerOn && !$titleOn) return 'null';

        $filters = [];

        if ($tickerOn) {
            $file      = $assetsDir . '/ticker.txt';
            $color     = $this->sanitizeColor($overlay['ticker_text_color'] ?? '#fcd116');
            $size      = max(12, (int) ($overlay['ticker_font_size'] ?? 22));
            $bgOpacity = number_format(min(1.0, max(0.0, (float) ($overlay['ticker_bg_opacity'] ?? 0.75))), 2, '.', '');
            $barH      = $size + 20;

            $filters[] = "drawtext=fontfile={$this->font}:textfile={$file}:reload=1"
                . ":fontsize={$size}:fontcolor={$color}"
                . ":x=w-mod(max(t\\*110\\,0)\\,w+tw):y=h-{$barH}+10"
                . ":box=1:boxcolor=0x000000@{$bgOpacity}:boxborderw=10"
                . ":shadowcolor=black:shadowx=1:shadowy=1";
        }

        if ($titleOn) {
            $file      = $assetsDir . '/title.txt';
            $color     = $this->sanitizeColor($overlay['title_text_color'] ?? '#ffffff');
            $size      = max(12, (int) ($overlay['title_font_size'] ?? 26));
            $bgHex     = $this->colorToHex($overlay['title_bg_color'] ?? '#1e293b');
            $bgOpacity = number_format(min(1.0, max(0.0, (float) ($overlay['title_bg_opacity'] ?? 0.85))), 2, '.', '');
            $tickerBarH = $tickerOn ? (max(12, (int) ($overlay['ticker_font_size'] ?? 22)) + 20) : 0;
            $yOffset   = $tickerBarH + $size + 16;

            $filters[] = "drawtext=fontfile={$this->font}:textfile={$file}:reload=1"
                . ":fontsize={$size}:fontcolor={$color}"
                . ":x=20:y=h-{$yOffset}"
                . ":box=1:boxcolor={$bgHex}@{$bgOpacity}:boxborderw=8";
        }

        return implode(',', $filters);
    }

    private function escapeDrawtext(string $text): string
    {
        // Escape special drawtext characters
        $text = str_replace(['\\', ':', "'", '%'], ['\\\\', '\\:', "\\'", '\\%'], $text);
        return "'{$text}'";
    }

    private function buildCommand(string $hlsSource, string $vf, string $pushUrl): string
    {
        if (!str_starts_with($pushUrl, 'rtmp://') && !str_starts_with($pushUrl, 'srt://')) {
            $pushUrl = 'srt://' . $pushUrl;
        }

        $isRtmp  = str_starts_with($pushUrl, 'rtmp://');
        $outFmt  = $isRtmp ? '-f flv' : '-f mpegts';
        $vfArg   = $vf === 'null' ? '' : "-vf \"{$vf}\"";

        return trim(implode(' ', array_filter([
            $this->ffmpeg,
            '-re -fflags +genpts+discardcorrupt',
            '-allowed_extensions ALL',
            '-protocol_whitelist file,crypto,data,http,https,tcp,tls',
            '-live_start_index -3',
            '-i ' . escapeshellarg($hlsSource),
            $vfArg,
            '-c:v libx264 -preset veryfast -tune zerolatency',
            '-b:v 1200k -maxrate 1400k -bufsize 2400k',
            '-g 50 -keyint_min 25 -sc_threshold 0',
            '-c:a aac -b:a 192k -ar 48000 -ac 2',
            $outFmt,
            escapeshellarg($pushUrl),
        ])));
    }

    private function syncLineFiles(array $overlay, string $assetsDir): void
    {
        $lines = $overlay['ticker_lines'] ?? [];
        foreach ($lines as $line) {
            if (empty($line['id'])) continue;
            $file = $assetsDir . '/ticker_line_' . $line['id'] . '.txt';
            file_put_contents($file, $line['text'] ?? ' ');
            @chmod($file, 0664);
        }
    }

    private function readOverlay(string $assetsDir): array
    {
        $path = $assetsDir . '/overlay.json';
        if (!file_exists($path)) return [];
        return json_decode(file_get_contents($path), true) ?? [];
    }

    private function sanitizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) return $color;
        if (preg_match('/^[a-zA-Z]+$/', $color)) return strtolower($color);
        return 'white';
    }

    private function colorToHex(string $color): string
    {
        $color = ltrim(trim($color), '#');
        return preg_match('/^[0-9a-fA-F]{6}$/', $color) ? '0x' . strtoupper($color) : '0x000000';
    }

    private function syncNowPlayingTitle(int $channelId, string $assetsDir): void
    {
        if (empty($assetsDir) || !is_dir($assetsDir)) return;

        $playlistsDir = $channelId === 1
            ? '/home/ffpu/playlists'
            : '/home/ffpu/playlists/' . $channelId;

        $dayStart = $this->playlistDayStart($channelId);

        $elapsed = time() - strtotime(date('Y-m-d') . ' ' . $dayStart);
        $date    = date('Y-m-d');
        if ($elapsed < 0) {
            $elapsed += 86400;
            $date = date('Y-m-d', time() - 86400);
        }

        [$y, $m] = explode('-', $date);
        $jsonFile = "$playlistsDir/{$y}/{$m}/{$date}.json";
        $program  = [];
        if (file_exists($jsonFile)) {
            $data    = json_decode(file_get_contents($jsonFile), true);
            $program = is_array($data) ? ($data['program'] ?? []) : [];
        }

        $titleFile = $assetsDir . '/title.txt';
        $isMovie   = false;
        $title     = '';

        if (!empty($program)) {
            $total = $pos = 0.0;
            foreach ($program as $item) {
                $total += max(0.0, (float) ($item['duration'] ?? ($item['out'] - $item['in'])));
            }
            if ($total > 0) {
                $pos = $elapsed % $total;
                foreach ($program as $candidate) {
                    $len = max(0.0, (float) ($candidate['duration'] ?? ($candidate['out'] - $candidate['in'])));
                    if ($pos < $len) {
                        $source = $candidate['source'] ?? '';
                        $isMovie = $source !== '' && file_exists($source)
                            && $len >= 15
                            && !str_contains(strtolower(dirname($source)), 'jingle');
                        if ($isMovie) {
                            $title = $this->cleanTitle($source);
                        }
                        break;
                    }
                    $pos -= $len;
                }
            }
        }

        $want = $isMovie ? 'Now Playing: ' . $title : '';
        if (trim((string) @file_get_contents($titleFile)) === $want) return;

        file_put_contents($titleFile, $want);
        @chmod($titleFile, 0664);

        $overlayFile = $assetsDir . '/overlay.json';
        if (file_exists($overlayFile)) {
            $overlay = json_decode(file_get_contents($overlayFile), true);
            if (is_array($overlay)) {
                $overlay['title_text'] = $want;
                file_put_contents($overlayFile, json_encode($overlay));
                @chmod($overlayFile, 0664);
            }
        }

        Log::info('[FfplayoutOverlayRelay] ch' . $channelId . ' title -> ' . ($want === '' ? '(hidden)' : $want));
    }

    private function playlistDayStart(int $channelId): string
    {
        try {
            $pdo = new \PDO('sqlite:/home/ffpu/db/ffplayout.db', null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $stmt = $pdo->prepare('SELECT playlist_day_start FROM configurations WHERE channel_id = ?');
            $stmt->execute([$channelId]);
            $val = (string) $stmt->fetchColumn();
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $val)) return $val;
        } catch (\Throwable) {}

        return '05:59:25';
    }

    private function cleanTitle(string $source): string
    {
        $base = pathinfo($source, PATHINFO_FILENAME);
        $base = preg_replace('/^\d+_+/', '', $base);
        $base = str_replace('_', ' ', $base);
        return trim(preg_replace('/\s+/', ' ', $base));
    }
}
