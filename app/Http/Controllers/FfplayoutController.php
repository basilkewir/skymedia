<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\FfplayoutDownload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SQLite3;

class FfplayoutController extends Controller
{
    private string $dbPath    = '/home/ffpu/db/ffplayout.db';
    private string $baseMedia = '/home/ffpu/media';
    private string $basePlaylists = '/home/ffpu/playlists';

    public function index(): Response
    {
        $channels  = $this->getChannels();
        $available = file_exists($this->dbPath);

        return Inertia::render('Ffplayout/Index', [
            'channels'  => $channels,
            'available' => $available,
        ]);
    }

    public function channels(): JsonResponse
    {
        return response()->json(['channels' => $this->getChannels()]);
    }

    public function media(int $channelId): JsonResponse
    {
        $dir = $this->mediaDir($channelId);
        $files = [];

        if (is_dir($dir)) {
            foreach (new \DirectoryIterator($dir) as $f) {
                if ($f->isDot() || $f->isDir()) continue;
                $ext = strtolower($f->getExtension());
                if (!in_array($ext, ['mp4', 'mkv', 'webm', 'ts', 'avi', 'mov'])) continue;
                $files[] = [
                    'filename'   => $f->getFilename(),
                    'size'       => $f->getSize(),
                    'size_human' => $this->formatBytes($f->getSize()),
                    'modified'   => date('Y-m-d H:i', $f->getMTime()),
                ];
            }
        }

        usort($files, fn($a, $b) => strcmp($b['modified'], $a['modified']));
        return response()->json(['files' => $files]);
    }

    public function download(Request $request, int $channelId): JsonResponse
    {
        $data = $request->validate([
            'url'   => 'required|string|max:8000',
            'title' => 'nullable|string|max:500',
        ]);

        $url   = trim($data['url']);
        $title = $data['title'] ?: $this->titleFromUrl($url);

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return response()->json(['success' => false, 'error' => 'URL must start with http:// or https://'], 422);
        }

        $downloadId = 'ffdl_' . $channelId . '_' . time() . '_' . substr(md5($url), 0, 6);

        FfplayoutDownload::dispatch(
            $downloadId, $channelId, $url, $title,
            $this->mediaDir($channelId),
            $this->playlistsDir($channelId),
        );

        return response()->json(['success' => true, 'download_id' => $downloadId, 'message' => "Downloading: {$title}"]);
    }

    public function downloadStatus(Request $request, int $channelId): JsonResponse
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids)) $ids = [$ids];

        $dir      = $this->mediaDir($channelId);
        $statuses = [];

        foreach ($ids as $id) {
            $id = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$id);
            $statusFile = "{$dir}/{$id}.status.json";
            $statuses[$id] = file_exists($statusFile)
                ? (json_decode(file_get_contents($statusFile), true) ?? ['status' => 'unknown'])
                : ['status' => 'queued'];
        }

        return response()->json(['statuses' => $statuses]);
    }

    public function restart(int $channelId): JsonResponse
    {
        exec('sudo systemctl restart ffplayout 2>&1', $out, $code);
        if ($code !== 0) {
            return response()->json(['success' => false, 'message' => implode(' ', $out)], 500);
        }
        return response()->json(['success' => true, 'message' => 'ffplayout restarted']);
    }

    public function overlayGet(int $channelId): JsonResponse
    {
        return response()->json($this->readOverlay($channelId));
    }

    /**
     * Save overlay settings.
     * ticker_lines is a JSON-encoded array of per-line config objects.
     */
    public function overlaySet(Request $request, int $channelId): JsonResponse
    {
        $data = $request->validate([
            'logo_enabled'      => 'boolean',
            'logo_position'     => 'nullable|string|max:100',
            'logo_opacity'      => 'nullable|numeric|min:0|max:1',
            'logo_scale'        => 'nullable|string|max:100',
            'ticker_enabled'    => 'boolean',
            'ticker_text'       => 'nullable|string|max:5000',
            'ticker_bg_color'   => 'nullable|string|max:20',
            'ticker_bg_opacity' => 'nullable|numeric|min:0|max:1',
            'ticker_text_color' => 'nullable|string|max:20',
            'ticker_font_size'  => 'nullable|integer|min:10|max:72',
            'title_enabled'     => 'boolean',
            'title_text'        => 'nullable|string|max:500',
            'title_text_color'  => 'nullable|string|max:20',
            'title_bg_color'    => 'nullable|string|max:20',
            'title_bg_opacity'  => 'nullable|numeric|min:0|max:1',
            'title_font_size'   => 'nullable|integer|min:10|max:72',
            'logo_file'         => 'nullable|file|mimes:png,jpg,jpeg,gif,svg|max:2048',
            'relay_push_url'    => 'nullable|string|max:500',
            'ticker_lines'      => 'nullable|string', // JSON-encoded array
        ]);

        $mediaDir  = $this->mediaDir($channelId);
        $assetsDir = $mediaDir . '/00-assets';
        if (!is_dir($assetsDir)) mkdir($assetsDir, 0775, true);

        $current = $this->readOverlay($channelId);

        // Handle logo upload
        $logoPath = $current['logo_path'];
        if ($request->hasFile('logo_file')) {
            $file = $request->file('logo_file');
            $ext  = $file->getClientOriginalExtension();
            $file->move($assetsDir, "logo.{$ext}");
            @chmod("{$assetsDir}/logo.{$ext}", 0664);
            $logoPath = "00-assets/logo.{$ext}";
        }

        $logoEnabled     = $data['logo_enabled']      ?? $current['logo_enabled'];
        $logoScale       = $data['logo_scale']         ?? $current['logo_scale'];
        $logoOpacity     = $data['logo_opacity']       ?? $current['logo_opacity'];
        $logoPosition    = $data['logo_position']      ?? $current['logo_position'];
        $tickerEnabled   = $data['ticker_enabled']     ?? $current['ticker_enabled'];
        $tickerText      = $data['ticker_text']        ?? $current['ticker_text'];
        $tickerBgColor   = $data['ticker_bg_color']    ?? $current['ticker_bg_color'];
        $tickerBgOpacity = (float)($data['ticker_bg_opacity'] ?? $current['ticker_bg_opacity']);
        $tickerTextColor = $data['ticker_text_color']  ?? $current['ticker_text_color'];
        $tickerFontSize  = (int)($data['ticker_font_size']    ?? $current['ticker_font_size']);
        $titleEnabled    = $data['title_enabled']      ?? $current['title_enabled'];
        $titleText       = $data['title_text']         ?? $current['title_text'];
        $titleTextColor  = $data['title_text_color']   ?? $current['title_text_color'];
        $titleBgColor    = $data['title_bg_color']     ?? $current['title_bg_color'];
        $titleBgOpacity  = (float)($data['title_bg_opacity']  ?? $current['title_bg_opacity']);
        $titleFontSize   = (int)($data['title_font_size']     ?? $current['title_font_size']);
        $relayPushUrl    = $data['relay_push_url']      ?? $current['relay_push_url'];

        // Write title/ticker legacy files
        $titleFile  = $assetsDir . '/title.txt';
        $tickerFile = $assetsDir . '/ticker.txt';
        if ($titleText !== null && $titleText !== '') {
            file_put_contents($titleFile, $titleText);
            @chmod($titleFile, 0664);
        } elseif (!file_exists($titleFile)) {
            file_put_contents($titleFile, ' ');
            @chmod($titleFile, 0664);
        }
        if (!file_exists($tickerFile)) {
            file_put_contents($tickerFile, $tickerText ?: ' ');
            @chmod($tickerFile, 0664);
        }

        // Update ffplayout SQLite (logo settings only)
        $this->writeOverlay($channelId, [
            'processing_add_logo'        => $logoEnabled ? 1 : 0,
            'processing_logo'            => $logoPath,
            'processing_logo_scale'      => $logoScale,
            'processing_logo_opacity'    => (float) $logoOpacity,
            'processing_logo_position'   => $logoPosition,
            'text_add'                   => 0,
            'text_from_filename'         => 0,
            'processing_filter'          => '',
            'processing_override_filter' => 0,
        ]);

        // Handle ticker_lines
        $tickerLines = $current['ticker_lines'] ?? [];
        if (isset($data['ticker_lines'])) {
            $decoded = json_decode($data['ticker_lines'], true);
            if (is_array($decoded)) {
                $labelsDir = $assetsDir . '/ticker_labels';
                if (!is_dir($labelsDir)) mkdir($labelsDir, 0775, true);

                $tickerLines = array_map(function ($l) use ($assetsDir) {
                    $id = preg_replace('/[^a-z0-9_]/', '', (string) ($l['id'] ?? ''));
                    if ($id === '') $id = 'line_' . substr(md5(uniqid()), 0, 8);
                    $line = [
                        'id'             => $id,
                        'enabled'        => (bool) ($l['enabled'] ?? true),
                        'text'           => substr((string) ($l['text'] ?? ''), 0, 2000),
                        'text_color'     => $this->sanitizeColor($l['text_color'] ?? '#fcd116'),
                        'bg_color'       => $this->sanitizeColor($l['bg_color'] ?? '#000000'),
                        'bg_opacity'     => min(1.0, max(0.0, (float) ($l['bg_opacity'] ?? 0.85))),
                        'font_size'      => min(72, max(10, (int) ($l['font_size'] ?? 22))),
                        'label_text'     => substr((string) ($l['label_text'] ?? ''), 0, 50),
                        'label_color'    => $this->sanitizeColor($l['label_color'] ?? '#ffffff'),
                        'label_bg_color' => $this->sanitizeColor($l['label_bg_color'] ?? '#c0392b'),
                        'label_image'    => basename((string) ($l['label_image'] ?? '')),
                    ];
                    // Write per-line text file for live reload
                    $lineFile = $assetsDir . '/ticker_line_' . $id . '.txt';
                    file_put_contents($lineFile, $line['text'] ?: ' ');
                    @chmod($lineFile, 0664);
                    return $line;
                }, $decoded);
            }
        }

        $sidecar = [
            'ticker_text'       => $tickerText,
            'ticker_enabled'    => $tickerEnabled,
            'ticker_bg_color'   => $tickerBgColor,
            'ticker_bg_opacity' => $tickerBgOpacity,
            'ticker_text_color' => $tickerTextColor,
            'ticker_font_size'  => $tickerFontSize,
            'title_text'        => $titleText,
            'title_enabled'     => $titleEnabled,
            'title_text_color'  => $titleTextColor,
            'title_bg_color'    => $titleBgColor,
            'title_bg_opacity'  => $titleBgOpacity,
            'title_font_size'   => $titleFontSize,
            'relay_push_url'    => $relayPushUrl,
            'ticker_lines'      => $tickerLines,
        ];
        file_put_contents($assetsDir . '/overlay.json', json_encode($sidecar));

        return response()->json(['success' => true, 'overlay' => $this->readOverlay($channelId)]);
    }

    /**
     * Upload a label image for a ticker line.
     * Stores in 00-assets/ticker_labels/{filename}.
     */
    public function tickerLabelImageUpload(Request $request, int $channelId): JsonResponse
    {
        $request->validate([
            'image' => 'required|file|mimes:png,jpg,jpeg,gif,svg|max:1024',
        ]);

        $assetsDir = $this->mediaDir($channelId) . '/00-assets';
        $labelsDir = $assetsDir . '/ticker_labels';
        if (!is_dir($labelsDir)) mkdir($labelsDir, 0775, true);

        $file     = $request->file('image');
        $ext      = $file->getClientOriginalExtension();
        $filename = 'label_' . time() . '_' . substr(md5($file->getClientOriginalName()), 0, 6) . '.' . $ext;
        $file->move($labelsDir, $filename);
        @chmod("{$labelsDir}/{$filename}", 0664);

        return response()->json(['success' => true, 'filename' => $filename]);
    }

    /**
     * Update a single ticker line's text file live (no relay restart needed).
     */
    public function tickerLineTextUpdate(Request $request, int $channelId): JsonResponse
    {
        $data = $request->validate([
            'line_id' => 'required|string|max:64|regex:/^[a-z0-9_]+$/',
            'text'    => 'nullable|string|max:2000',
        ]);

        $assetsDir = $this->mediaDir($channelId) . '/00-assets';
        $lineFile  = $assetsDir . '/ticker_line_' . $data['line_id'] . '.txt';
        file_put_contents($lineFile, $data['text'] ?: ' ');
        @chmod($lineFile, 0664);

        // Update sidecar JSON
        $sidecarPath = $assetsDir . '/overlay.json';
        $sidecar = file_exists($sidecarPath)
            ? (json_decode(file_get_contents($sidecarPath), true) ?? [])
            : [];
        if (isset($sidecar['ticker_lines'])) {
            foreach ($sidecar['ticker_lines'] as &$line) {
                if ($line['id'] === $data['line_id']) {
                    $line['text'] = $data['text'];
                    break;
                }
            }
        }
        file_put_contents($sidecarPath, json_encode($sidecar));

        return response()->json(['success' => true]);
    }

    public function rssFetch(int $channelId): JsonResponse
    {
        \Artisan::call('ffplayout:rss-ticker', ['--channels' => (string) $channelId]);
        return response()->json(['success' => true, 'overlay' => $this->readOverlay($channelId)]);
    }

    public function overlayTextUpdate(Request $request, int $channelId): JsonResponse
    {
        $data = $request->validate([
            'field' => 'required|in:ticker,title',
            'text'  => 'nullable|string|max:5000',
        ]);

        $assetsDir = $this->mediaDir($channelId) . '/00-assets';
        $file = $assetsDir . '/' . $data['field'] . '.txt';
        file_put_contents($file, $data['text'] ?: ' ');
        @chmod($file, 0664);

        $sidecarPath = $assetsDir . '/overlay.json';
        $sidecar = file_exists($sidecarPath)
            ? (json_decode(file_get_contents($sidecarPath), true) ?? [])
            : [];
        $sidecar[$data['field'] . '_text'] = $data['text'];
        file_put_contents($sidecarPath, json_encode($sidecar));

        return response()->json(['success' => true]);
    }

    public function relayStart(int $channelId): JsonResponse
    {
        $overlay = $this->readOverlay($channelId);
        if (empty($overlay['relay_push_url'])) {
            return response()->json(['success' => false, 'message' => 'Set a relay push URL first'], 422);
        }

        exec('supervisorctl start skymedia-relay-' . $channelId . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $log = "/var/log/skymedia/relay-{$channelId}.log";
            exec(
                'nohup sudo -u www-data /usr/bin/php8.3 /var/www/skymedia/artisan '
                . 'ffplayout:overlay-relay --channel=' . $channelId
                . " >> {$log} 2>&1 &",
                $out2, $code2
            );
            if ($code2 !== 0) {
                return response()->json(['success' => false, 'message' => implode(' ', $out2)], 500);
            }
        }

        return response()->json(['success' => true, 'message' => 'Overlay relay started']);
    }

    public function relayStop(int $channelId): JsonResponse
    {
        exec('supervisorctl stop skymedia-relay-' . $channelId . ' 2>&1', $out, $code);
        exec('pkill -f "ffplayout:overlay-relay --channel=' . $channelId . '" 2>&1', $out2, $code2);
        return response()->json(['success' => true, 'message' => 'Overlay relay stopped']);
    }

    public function relayStatus(int $channelId): JsonResponse
    {
        exec('pgrep -f "ffplayout:overlay-relay --channel=' . (int) $channelId . '" 2>&1', $out, $code);
        $running = $code === 0 && !empty($out[0]);
        return response()->json(['running' => $running, 'pid' => $running ? (int) trim((string) $out[0]) : null, 'channel' => (int) $channelId]);
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

    private function readOverlay(int $channelId): array
    {
        $defaults = [
            'logo_enabled'      => true,
            'logo_path'         => '00-assets/logo.png',
            'logo_scale'        => '100:-1',
            'logo_opacity'      => 0.7,
            'logo_position'     => 'W-w-12:12',
            'ticker_enabled'    => false,
            'ticker_text'       => '',
            'ticker_bg_color'   => '#000000',
            'ticker_bg_opacity' => 0.75,
            'ticker_text_color' => '#fcd116',
            'ticker_font_size'  => 22,
            'title_enabled'     => false,
            'title_text'        => '',
            'title_text_color'  => '#ffffff',
            'title_bg_color'    => '#1e293b',
            'title_bg_opacity'  => 0.85,
            'title_font_size'   => 26,
            'relay_push_url'    => '',
            'ticker_lines'      => [],
        ];

        if (!file_exists($this->dbPath)) return $defaults;

        try {
            $db   = new SQLite3($this->dbPath, SQLITE3_OPEN_READONLY);
            $stmt = $db->prepare('SELECT processing_add_logo,processing_logo,processing_logo_scale,processing_logo_opacity,processing_logo_position FROM configurations WHERE channel_id=:id');
            $stmt->bindValue(':id', $channelId, SQLITE3_INTEGER);
            $row  = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            $db->close();

            if (!$row) return $defaults;

            $result = [
                'logo_enabled'  => (bool) $row['processing_add_logo'],
                'logo_path'     => $row['processing_logo'],
                'logo_scale'    => $row['processing_logo_scale'] ?: '100:-1',
                'logo_opacity'  => (float) $row['processing_logo_opacity'],
                'logo_position' => $row['processing_logo_position'],
            ] + $defaults;

            $sidecarPath = $this->mediaDir($channelId) . '/00-assets/overlay.json';
            if (file_exists($sidecarPath)) {
                $sc = json_decode(file_get_contents($sidecarPath), true) ?? [];
                foreach (['ticker_enabled', 'ticker_bg_color', 'ticker_bg_opacity', 'ticker_text_color',
                          'ticker_font_size', 'title_enabled', 'title_text', 'title_text_color',
                          'title_bg_color', 'title_bg_opacity', 'title_font_size', 'relay_push_url'] as $key) {
                    if (array_key_exists($key, $sc)) $result[$key] = $sc[$key];
                }
                $result['ticker_lines'] = $sc['ticker_lines'] ?? [];
            }

            // Always read live text files
            $tickerFile = $this->mediaDir($channelId) . '/00-assets/ticker.txt';
            if (file_exists($tickerFile)) $result['ticker_text'] = trim(file_get_contents($tickerFile));

            $titleFile = $this->mediaDir($channelId) . '/00-assets/title.txt';
            if (file_exists($titleFile)) {
                $live = trim(file_get_contents($titleFile));
                if ($live !== '') $result['title_text'] = $live;
            }

            // Sync live text into ticker_lines[0] if it's the RSS line
            if (!empty($result['ticker_lines'][0]['id']) && $result['ticker_lines'][0]['id'] === 'rss') {
                $result['ticker_lines'][0]['text'] = $result['ticker_text'];
            }

            return $result;
        } catch (\Throwable) {
            return $defaults;
        }
    }

    private function writeOverlay(int $channelId, array $fields): void
    {
        if (!file_exists($this->dbPath)) return;

        try {
            $db   = new SQLite3($this->dbPath);
            $sets = implode(', ', array_map(fn($k) => "{$k}=:{$k}", array_keys($fields)));
            $stmt = $db->prepare("UPDATE configurations SET {$sets} WHERE channel_id=:channel_id");
            foreach ($fields as $k => $v) {
                $type = is_int($v) ? SQLITE3_INTEGER : (is_float($v) ? SQLITE3_FLOAT : SQLITE3_TEXT);
                $stmt->bindValue(":{$k}", $v, $type);
            }
            $stmt->bindValue(':channel_id', $channelId, SQLITE3_INTEGER);
            $stmt->execute();
            $db->close();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("[FfplayoutOverlay] {$e->getMessage()}");
        }
    }

    public function playlist(int $channelId): JsonResponse
    {
        $today    = date('Y-m-d');
        [$y, $m]  = explode('-', $today);
        $jsonFile = $this->playlistsDir($channelId) . "/{$y}/{$m}/{$today}.json";

        if (!file_exists($jsonFile)) {
            return response()->json(['program' => [], 'date' => $today]);
        }

        return response()->json(json_decode(file_get_contents($jsonFile), true) ?? []);
    }

    private function getChannels(): array
    {
        if (!file_exists($this->dbPath)) return [];

        try {
            $db     = new SQLite3($this->dbPath, SQLITE3_OPEN_READONLY);
            $result = $db->query('SELECT id, name, preview_url, active FROM channels ORDER BY id');
            $rows   = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
            $db->close();
            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    private function mediaDir(int $channelId): string
    {
        return $channelId === 1 ? $this->baseMedia : "{$this->baseMedia}/{$channelId}";
    }

    private function playlistsDir(int $channelId): string
    {
        return $channelId === 1 ? $this->basePlaylists : "{$this->basePlaylists}/{$channelId}";
    }

    private function titleFromUrl(string $url): string
    {
        $base = basename((string) strtok($url, '?'));
        return $base !== '' ? urldecode($base) : 'download_' . time();
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) { $bytes /= 1024; $i++; }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
