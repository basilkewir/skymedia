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
    private array $pushBackoff = [];  // channel_id => backoff seconds

    private array $pushLastRetry = [];  // channel_id => timestamp

    // Per-destination backoff state
    private array $destBackoff = [];  // dest_id => backoff seconds

    private array $destLastRetry = [];  // dest_id => timestamp

    public function __construct(
        protected FFmpegService $ffmpeg,
        protected PlayoutService $playout,
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    //  PRIMARY PUSH (channel.push_url / push_stream_key)
    // ═══════════════════════════════════════════════════════════════════

    public function start(Channel $channel, bool $force = false): bool
    {
        Log::info("[Debug] PushService::start {$channel->name} begin");
        if (empty($channel->push_url)) {
            Log::warning("[Push] {$channel->name}: push_url is empty");

            return false;
        }

        // Already running and healthy — push reads output.m3u8 which the playout module
        // swaps atomically underneath. Never restart for playlist changes.
        // When force=true (live↔fallback transition), always restart.
        Log::info("[Debug] PushService::start {$channel->name} checking healthy");
        if (! $force && $this->isHealthy($channel)) {
            Log::info("[Debug] PushService::start {$channel->name} already healthy");

            return true;
        }

        // Process is alive but log shows a fatal error — stop it before restart.
        Log::info("[Debug] PushService::start {$channel->name} checking running");
        if ($this->isRunning($channel)) {
            Log::info("[Debug] PushService::start {$channel->name} running but unhealthy, stopping");
            $this->stopPrimary($channel);
        }

        // Also kill any orphan push processes not tracked by the PID file.
        Log::info("[Debug] PushService::start {$channel->name} killing orphans");
        $this->killOrphanPushProcesses($channel);

        // Verify all old push processes are actually dead before starting a new one.
        $verifyCmd = $this->buildFindPushCmd($channel);
        for ($i = 0; $i < 10; $i++) {
            exec($verifyCmd, $lines);
            $remaining = array_filter(array_map('intval', $lines));
            if (empty($remaining)) {
                break;
            }
            usleep(100_000); // 100ms
        }

        Log::info("[Debug] PushService::start {$channel->name} getting playlist");
        $playlist = $this->playout->outputPlaylist($channel);
        Log::info("[Debug] PushService::start {$channel->name} playlist={$playlist} exists=" . (file_exists($playlist) ? 'yes' : 'no') . ' is_link=' . (is_link($playlist) ? 'yes' : 'no'));
        if (! file_exists($playlist) && ! is_link($playlist)) {
            Log::warning("[Push] {$channel->name}: playlist not ready ({$playlist})");

            return false;
        }

        Log::info("[Debug] PushService::start {$channel->name} building command");
        $cmd = $this->ffmpeg->buildPushCommand($channel, $playlist);
        Log::info("[Debug] PushService::start {$channel->name} launching push");
        $pid = $this->launchPush($cmd, $channel, 'push');

        if ($pid === null) {
            $logTail = $this->ffmpeg->readLogTail($this->ffmpeg->logFile($channel, 'push'), 20);
            Log::error("[Push] {$channel->name} failed — cmd: " . implode(' ', $cmd));
            if ($logTail && ! str_starts_with($logTail, '(log not found')) {
                Log::error("[Push] {$channel->name} log tail:\n{$logTail}");
            }
            $channel->update([
                'push_pid' => null,
                'push_status' => 'error',
                'last_error' => substr("ffmpeg failed to start\n{$logTail}", 0, 1000),
            ]);

            return false;
        }

        // Reset backoff on successful start
        $this->pushBackoff[$channel->id] = 2;
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
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $pid = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0 && $this->ffmpeg->isRunning($pid)) {
            return true;
        }

        // PID file stale — reconcile with the actual push process.
        $pids = $this->findPushPids($channel);
        if (! empty($pids)) {
            file_put_contents($pidFile, (string) $pids[0]);

            return true;
        }

        return false;
    }

    /**
     * True when the push process is running AND the log shows it is actually
     * connected and streaming. A hung or auth-failed process is not healthy.
     */
    public function isHealthy(Channel $channel): bool
    {
        if (! $this->isRunning($channel)) {
            return false;
        }

        return $this->ffmpeg->isPushConnected($this->ffmpeg->logFile($channel, 'push'));
    }

    /**
     * Watchdog: called every monitor tick. Restarts push if it died,
     * using exponential backoff (2s → 4s → 8s … capped at 30s).
     * Auth failures are NOT retried until the operator fixes credentials.
     * Returns true if push is running after this call.
     */
    public function ensureRunning(Channel $channel): bool
    {
        if (empty($channel->push_url)) {
            return false;
        }

        if ($this->isHealthy($channel)) {
            // Running fine — reset backoff
            $this->pushBackoff[$channel->id] = 2;

            return true;
        }

        // Auth failure: do not retry automatically.
        // Check BOTH the DB last_error field AND the push log directly,
        // because last_error is cleared to null on each successful start
        // but the log retains fatal auth errors from the current session.
        $lastError = strtolower($channel->last_error ?? '');
        if ($lastError === '' || ! str_contains($lastError, 'auth')) {
            // Also inspect the push log for the current ffmpeg session.
            $logTail = strtolower($this->ffmpeg->readLogTail(
                $this->ffmpeg->logFile($channel, 'push'), 40
            ));
            foreach (['authfailed', 'accessmanager', 'incorrect username/password'] as $pattern) {
                if (str_contains($logTail, $pattern)) {
                    $lastError = $pattern;
                    break;
                }
            }
        }

        $isAuthFailure = str_contains($lastError, 'authfailed')
            || str_contains($lastError, 'accessmanager')
            || str_contains($lastError, 'authentication failed')
            || str_contains($lastError, 'unauthorized')
            || str_contains($lastError, 'incorrect key')
            || str_contains($lastError, 'incorrect username/password')
            || str_contains($lastError, 'no authority');

        if ($isAuthFailure) {
            if ($channel->push_status !== 'error') {
                $channel->update(['push_status' => 'error', 'last_error' => 'Auth rejected — check push credentials']);
                Log::error("[Push] {$channel->name}: auth rejected — fix credentials to resume");
            }

            return false;
        }

        // Enforce backoff window
        $backoff = $this->pushBackoff[$channel->id] ?? 2;
        $lastRetry = $this->pushLastRetry[$channel->id] ?? 0;
        if ((time() - $lastRetry) < $backoff) {
            return false; // still in cooldown
        }

        // Attempt restart
        $playlist = $this->playout->outputPlaylist($channel);
        if (! file_exists($playlist) && ! is_link($playlist)) {
            return false;
        }

        Log::warning("[Push] {$channel->name}: not running — restarting (backoff={$backoff}s)");
        $this->stopPrimary($channel);
        $cmd = $this->ffmpeg->buildPushCommand($channel, $playlist);
        $pid = $this->launchPush($cmd, $channel, 'push');

        // Update backoff: double it, cap at 30s
        $this->pushLastRetry[$channel->id] = time();
        $this->pushBackoff[$channel->id] = min(30, $backoff * 2);

        if ($pid === null) {
            $logTail = $this->ffmpeg->readLogTail($this->ffmpeg->logFile($channel, 'push'), 10);
            $channel->update([
                'push_pid' => null,
                'push_status' => 'error',
                'last_error' => substr("push restart failed\n{$logTail}", 0, 1000),
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
        if (! file_exists($playlist) && ! is_link($playlist)) {
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
            $backoff = $this->destBackoff[$dest->id] ?? 2;
            $lastRetry = $this->destLastRetry[$dest->id] ?? 0;
            if ((time() - $lastRetry) < $backoff) {
                continue;
            }

            Log::warning("[Push] {$channel->name} → {$dest->name}: died — restarting (backoff={$backoff}s)");
            $cmd = $this->ffmpeg->buildPushCommand($channel, $playlist, $dest->protocol, $dest);
            $newPid = $this->launchPush($cmd, $channel, "push_dest_{$dest->id}");

            $this->destLastRetry[$dest->id] = time();
            $this->destBackoff[$dest->id] = min(30, $backoff * 2);

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
        $this->killOrphanPushProcesses($channel);
        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['push_pid' => null]);
    }

    /**
     * Kill any ffmpeg primary push processes for this channel that are not
     * tracked by the PID file. Prevents duplicate pushes after restarts.
     */
    private function killOrphanPushProcesses(Channel $channel): int
    {
        $playlist = $this->playout->outputPlaylist($channel);
        $streamKey = $channel->push_stream_key ?? '';
        $baseUrl = rtrim($channel->push_url ?? '', '/');

        if ($baseUrl === '') {
            return 0;
        }

        $pids = $this->findPushPids($channel, $playlist, $baseUrl, $streamKey);

        $count = 0;
        foreach ($pids as $pid) {
            if ($pid > 0) {
                exec("kill -KILL {$pid} 2>/dev/null");
                $count++;
            }
        }

        if ($count > 0) {
            Log::warning("[Push] {$channel->name} killed {$count} orphan push process(es)");
        }

        return $count;
    }

    /**
     * Find PIDs of ffmpeg primary push processes for this channel.
     */
    private function findPushPids(
        Channel $channel,
        ?string $playlist = null,
        ?string $baseUrl = null,
        ?string $streamKey = null,
    ): array {
        exec($this->buildFindPushCmd($channel, $playlist, $baseUrl, $streamKey), $lines);

        return array_values(array_filter(array_map('intval', $lines)));
    }

    /**
     * Build the shell command to find push PIDs for this channel.
     */
    private function buildFindPushCmd(
        Channel $channel,
        ?string $playlist = null,
        ?string $baseUrl = null,
        ?string $streamKey = null,
    ): string {
        $playlist ??= $this->playout->outputPlaylist($channel);
        $streamKey ??= $channel->push_stream_key ?? '';
        $baseUrl ??= rtrim($channel->push_url ?? '', '/');

        if ($baseUrl === '') {
            return 'echo 0';
        }

        $cmd = 'ps aux | grep -F ' . escapeshellarg($playlist)
            . " | grep -F 'ffmpeg' | grep -v grep"
            . ' | grep -F ' . escapeshellarg($baseUrl);

        if ($streamKey !== '') {
            $cmd .= ' | grep -F ' . escapeshellarg($streamKey);
        }

        $cmd .= " | grep -vF 'rtmp:1935/static' | awk '{print \$2}' 2>/dev/null";

        return $cmd;
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
