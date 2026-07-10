<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use App\Models\PushDestination;
use Illuminate\Support\Facades\Log;

/**
 * PushService — pushes the channel output to external RTMP/SRT servers.
 *
 * Supports two modes:
 *   1. Primary push (legacy): uses channel.push_url / push_stream_key
 *      PID stored in channel.push_pid
 *   2. Multi-destination: additional PushDestination records per channel
 *      Each destination runs its own FFmpeg process with its own PID
 *
 * Reads whichever playlist PlayoutService says is current:
 *   live     → live.m3u8
 *   fallback → playout.m3u8
 */
class PushService
{
    // Per-channel push restart backoff state (in-memory, per daemon process)
    private array $pushBackoff   = [];  // channel_id => backoff seconds
    private array $pushLastRetry = [];  // channel_id => timestamp

    // Per-destination backoff state
    private array $destBackoff   = [];  // dest_id => backoff seconds
    private array $destLastRetry = [];  // dest_id => timestamp

    public function __construct(
        protected FFmpegService $ffmpeg,
        protected PlayoutService $playout,
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    //  PRIMARY PUSH (channel.push_url / push_stream_key)
    // ═══════════════════════════════════════════════════════════════════

    public function start(Channel $channel): bool
    {
        if (empty($channel->push_url)) {
            Log::warning("[Push] {$channel->name}: push_url is empty");
            return false;
        }

        // Already running — push reads output.m3u8 which the playout module
        // swaps atomically underneath. Never restart for playlist changes.
        if ($this->isRunning($channel)) {
            return true;
        }

        $playlist = $this->playout->outputPlaylist($channel);
        if (! file_exists($playlist)) {
            Log::warning("[Push] {$channel->name}: playlist not ready ({$playlist})");
            return false;
        }

        $this->stopPrimary($channel);

        $cmd = $this->ffmpeg->buildPushCommand($channel, $playlist);
        $pid = $this->launchPush($cmd, $channel, 'push');

        if ($pid === null) {
            $logTail = $this->ffmpeg->readLogTail($this->ffmpeg->logFile($channel, 'push'), 20);
            Log::error("[Push] {$channel->name} failed — cmd: " . implode(' ', $cmd));
            if ($logTail && ! str_starts_with($logTail, '(log not found')) {
                Log::error("[Push] {$channel->name} log tail:\n{$logTail}");
            }
            $channel->update([
                'push_pid'    => null,
                'push_status' => 'error',
                'last_error'  => substr("ffmpeg failed to start\n{$logTail}", 0, 1000),
            ]);
            return false;
        }

        // Reset backoff on successful start
        $this->pushBackoff[$channel->id]   = 2;
        $this->pushLastRetry[$channel->id] = time();

        $channel->update(['push_pid' => $pid, 'push_status' => 'live']);
        Log::info("[Push] {$channel->name} started — PID {$pid} — reading {$playlist}");

        $this->startDestinations($channel, $playlist);
        return true;
    }

    public function stop(Channel $channel): void
    {
        $this->stopDestinations($channel);
        $this->stopPrimary($channel);
        unset($this->pushBackoff[$channel->id], $this->pushLastRetry[$channel->id]);
        $channel->update(['push_status' => 'stopped']);
    }

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'push'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    /**
     * Watchdog: called every monitor tick. Restarts push if it died,
     * using exponential backoff (2s → 4s → 8s … capped at 30s).
     * Auth failures are NOT retried until the operator fixes credentials.
     * Returns true if push is running after this call.
     */
    public function ensureRunning(Channel $channel): bool
    {
        if (empty($channel->push_url)) return false;

        if ($this->isRunning($channel)) {
            // Running fine — reset backoff
            $this->pushBackoff[$channel->id] = 2;
            return true;
        }

        // Auth failure: do not retry automatically
        $lastError = $channel->last_error ?? '';
        if (str_contains($lastError, 'authfailed') || str_contains($lastError, 'AccessManager')) {
            if ($channel->push_status !== 'error') {
                $channel->update(['push_status' => 'error']);
                Log::error("[Push] {$channel->name}: auth rejected — fix credentials to resume");
            }
            return false;
        }

        // Enforce backoff window
        $backoff   = $this->pushBackoff[$channel->id]   ?? 2;
        $lastRetry = $this->pushLastRetry[$channel->id] ?? 0;
        if ((time() - $lastRetry) < $backoff) {
            return false; // still in cooldown
        }

        // Attempt restart
        $playlist = $this->playout->outputPlaylist($channel);
        if (! file_exists($playlist)) {
            return false;
        }

        Log::warning("[Push] {$channel->name}: not running — restarting (backoff={$backoff}s)");
        $this->stopPrimary($channel);
        $cmd = $this->ffmpeg->buildPushCommand($channel, $playlist);
        $pid = $this->launchPush($cmd, $channel, 'push');

        // Update backoff: double it, cap at 30s
        $this->pushLastRetry[$channel->id] = time();
        $this->pushBackoff[$channel->id]   = min(30, $backoff * 2);

        if ($pid === null) {
            $logTail = $this->ffmpeg->readLogTail($this->ffmpeg->logFile($channel, 'push'), 10);
            $channel->update([
                'push_pid'    => null,
                'push_status' => 'error',
                'last_error'  => substr("push restart failed\n{$logTail}", 0, 1000),
            ]);
            Log::error("[Push] {$channel->name}: restart failed (backoff now {$this->pushBackoff[$channel->id]}s)");
            return false;
        }

        // Reset backoff on success
        $this->pushBackoff[$channel->id] = 2;
        $channel->update(['push_pid' => $pid, 'push_status' => 'live', 'last_error' => null]);
        Log::info("[Push] {$channel->name}: restarted — PID {$pid}");
        return true;
    }

    public function startDvrPlayback(Channel $channel): bool
    {
        $concat = $channel->dvr_directory . '/concat.txt';
        if (! file_exists($concat)) {
            Log::warning("[Push] {$channel->name}: concat.txt not available");
            return false;
        }

        $this->stopPrimary($channel);
        $pid = $this->launchPush(
            $this->ffmpeg->buildDvrPlaybackCommand($channel),
            $channel,
            'push'
        );

        if ($pid === null) {
            $channel->update(['push_pid' => null, 'push_status' => 'error']);
            return false;
        }

        $channel->update(['push_pid' => $pid, 'push_status' => 'dvr_playback']);
        Log::info("[Push] {$channel->name} DVR playback started — PID {$pid}");
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  MULTI-DESTINATION PUSH (PushDestination records)
    // ═══════════════════════════════════════════════════════════════════

    public function startDestinations(Channel $channel, ?string $playlist = null): void
    {
        $playlist ??= $this->playout->outputPlaylist($channel);
        if (! file_exists($playlist)) {
            return;
        }

        foreach ($channel->pushDestinations()->where('enabled', true)->get() as $dest) {
            if ($dest->pid && $this->ffmpeg->isRunning((int) $dest->pid)) {
                continue;
            }

            $cmd = $this->ffmpeg->buildPushCommand($channel, $playlist, $dest->protocol, $dest);

            $pid = $this->launchPush($cmd, $channel, "push_dest_{$dest->id}");
            if ($pid !== null) {
                $dest->update(['pid' => $pid, 'status' => 'live', 'last_active_at' => now()]);
                Log::info("[Push] {$channel->name} → {$dest->name} started — PID {$pid}");
            } else {
                $dest->update(['status' => 'error']);
                Log::error("[Push] {$channel->name} → {$dest->name} failed");
            }
        }
    }

    public function stopDestinations(Channel $channel): void
    {
        foreach ($channel->pushDestinations as $dest) {
            if ($dest->pid && $this->ffmpeg->isRunning((int) $dest->pid)) {
                $this->ffmpeg->stopProcess((int) $dest->pid);
            }
            $this->ffmpeg->clearPid($this->ffmpeg->pidFile($channel, "push_dest_{$dest->id}"));
            $dest->update(['pid' => null, 'status' => 'stopped']);
        }
    }

    public function watchDestinations(Channel $channel, string $playlist): void
    {
        foreach ($channel->pushDestinations()->where('enabled', true)->get() as $dest) {
            $pid = (int) ($dest->pid ?? 0);
            if ($pid > 0 && $this->ffmpeg->isRunning($pid)) {
                // Running fine — reset backoff
                $this->destBackoff[$dest->id] = 2;
                continue;
            }

            // Enforce per-destination backoff
            $backoff   = $this->destBackoff[$dest->id]   ?? 2;
            $lastRetry = $this->destLastRetry[$dest->id] ?? 0;
            if ((time() - $lastRetry) < $backoff) {
                continue;
            }

            Log::warning("[Push] {$channel->name} → {$dest->name}: died — restarting (backoff={$backoff}s)");
            $cmd = $this->ffmpeg->buildPushCommand($channel, $playlist, $dest->protocol, $dest);
            $newPid = $this->launchPush($cmd, $channel, "push_dest_{$dest->id}");

            $this->destLastRetry[$dest->id] = time();
            $this->destBackoff[$dest->id]   = min(30, $backoff * 2);

            if ($newPid !== null) {
                $this->destBackoff[$dest->id] = 2;
                $dest->update(['pid' => $newPid, 'status' => 'live', 'last_active_at' => now()]);
            } else {
                $dest->update(['status' => 'error']);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  INTERNAL
    // ═══════════════════════════════════════════════════════════════════

    private function launchPush(array $cmd, Channel $channel, string $type): ?int
    {
        $pidFile = $this->ffmpeg->pidFile($channel, $type);
        $logFile = $this->ffmpeg->logFile($channel, $type);

        try {
            return $this->ffmpeg->startProcess($cmd, $pidFile, $logFile, 8);
        } catch (\Throwable $e) {
            Log::error("[Push] {$channel->name} {$type} failed: {$e->getMessage()}");

            return null;
        }
    }

    private function stopPrimary(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $pid = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }
        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['push_pid' => null]);
    }

    private function buildDestinationUrl(PushDestination $dest): string
    {
        $baseUrl = rtrim($dest->url, '/');
        $prefix = $dest->stream_key ? trim($dest->stream_key, '/') . '/' : '';

        if ($dest->protocol === 'hls') {
            if (str_starts_with($baseUrl, 'http://') || str_starts_with($baseUrl, 'https://')) {
                return "{$baseUrl}/{$prefix}index.m3u8";
            }
            $dir = "{$baseUrl}/{$prefix}";
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            return "{$dir}index.m3u8";
        }

        $target = $baseUrl . '/' . $dest->stream_key;

        if ($dest->protocol === 'srt') {
            $latency = config('skymedia.srt_latency', 200) * 1000;
            $base = preg_replace('#^srt://#', '', $target);
            $query = "latency={$latency}&mode=caller";
            if ($dest->username) {
                $query .= '&username=' . urlencode($dest->username);
            }
            if ($dest->password) {
                $query .= '&passphrase=' . urlencode($dest->password);
            }

            return "srt://{$base}?{$query}";
        }

        if ($dest->username || $dest->password) {
            $user = urlencode($dest->username ?? '');
            $pass = urlencode($dest->password ?? '');
            $target = preg_replace('#^(rtmps?://)#', "$1{$user}:{$pass}@", $target);
        }

        return $target;
    }
}
