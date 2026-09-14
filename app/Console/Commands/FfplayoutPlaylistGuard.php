<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Self-healing guard for ffplayout day playlists.
 *
 * The ffplayout GUI playlist generator sometimes saves channel playlists with
 * a doubled channel-segment prefix (e.g. /home/ffpu/media/2/2/<file>.mp4).
 * Every entry then fails validation, ffplayout falls back to filler, and the
 * channel shows a black screen with just the logo until someone repairs it.
 *
 * This command canonicalizes each entry's source path (de-duplicating the
 * channel segment and re-resolving against the real media roots), rewrites the
 * day JSON when something was fixed, and restarts ffplayout so the repaired
 * playlist is actually loaded. Restarts are rate-limited to avoid storms.
 *
 * Schedule: run every minute via the scheduler.
 */
class FfplayoutPlaylistGuard extends Command
{
    protected $signature = 'ffplayout:guard-playlist';

    protected $description = 'Repair ffplayout day playlist files (doubled channel-path prefixes) and reload the engine when fixed';

    private string $mediaRoot = '/home/ffpu/media';

    private string $playlistsRoot = '/home/ffpu/playlists';

    private string $restartStamp = '/tmp/ffplayout_guard_restart_time';

    private int $restartIntervalSeconds = 300;

    public function handle(): int
    {
        $changed = false;

        foreach ([1, 2] as $channelId) {
            $jsonFile = $this->todayFile($channelId);
            if (! is_file($jsonFile)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($jsonFile), true);
            if (! is_array($data) || ! isset($data['program']) || ! is_array($data['program'])) {
                continue;
            }

            $dirty = false;
            foreach ($data['program'] as &$item) {
                if (! isset($item['source']) || ! is_string($item['source'])) {
                    continue;
                }
                $orig = $item['source'];
                $item['source'] = $this->resolveSource($orig, $channelId);
                if ($item['source'] !== $orig) {
                    $dirty = true;
                    $this->line("[ch{$channelId}] {$orig} -> {$item['source']}");
                }
            }
            unset($item);

            if ($dirty) {
                file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $changed = true;
                Log::warning("[FfplayoutPlaylistGuard] repaired ch{$channelId} playlist: {$jsonFile}");
            }
        }

        if ($changed) {
            $this->restartEngine();
        }

        return 0;
    }

    /**
     * Return the entry's source if it exists, otherwise try to fix the
     * doubled-channel / wrong-root paths that the GUI generator produces.
     */
    private function resolveSource(string $source, int $channelId): string
    {
        if ($source === '' || file_exists($source)) {
            return $source;
        }

        $prefix = $this->mediaRoot;
        if (! str_starts_with($source, $prefix . '/')) {
            return $source;
        }

        $rel = substr($source, strlen($prefix) + 1);
        $parts = explode('/', $rel);
        if (count($parts) > 3) {
            $parts = array_filter(array_slice($parts, 0, 3));
        }

        $roots = [$this->mediaRoot];
        if ($channelId !== 1) {
            array_unshift($roots, "{$this->mediaRoot}/{$channelId}");
        }

        // Drop leading segments one at a time (removes the duplicated <ch>/<ch>/)
        // and try every media root until something on disk matches.
        for ($n = 0; $n < count($parts); $n++) {
            $joined = implode('/', array_slice($parts, $n));
            if ($joined === '') {
                continue;
            }
            foreach ($roots as $root) {
                $candidate = "{$root}/{$joined}";
                if (file_exists($candidate)) {
                    return $candidate;
                }
            }
        }

        return $source;
    }

    private function todayFile(int $channelId): string
    {
        $today = date('Y-m-d');
        [$y, $m] = explode('-', $today);
        $base = $channelId === 1
            ? $this->playlistsRoot
            : "{$this->playlistsRoot}/{$channelId}";

        return "{$base}/{$y}/{$m}/{$today}.json";
    }

    private function restartEngine(): void
    {
        $now = time();
        $last = (int) (file_exists($this->restartStamp) ? (int) file_get_contents($this->restartStamp) : 0);

        if ($now - $last < $this->restartIntervalSeconds) {
            Log::warning("[FfplayoutPlaylistGuard] repair done but restart throttled (last " . ($now - $last) . 's ago)');
            return;
        }

        @file_put_contents($this->restartStamp, (string) $now);
        exec('sudo systemctl restart ffplayout 2>&1', $out, $code);
        Log::warning('[FfplayoutPlaylistGuard] ' . ($code === 0
            ? 'ffplayout restarted after playlist repair'
            : 'restart failed: ' . implode(' ', $out)));
    }
}