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
    public function __construct(
        protected FFmpegService $ffmpeg,
        protected PlayoutService $playout,
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    //  PRIMARY PUSH (channel.push_url / push_stream_key)
    // ═══════════════════════════════════════════════════════════════════

    public function start(Channel $channel): bool
    {
        if (empty($channel->push_url)) return false;

        $playlist = $this->playout->outputPlaylist($channel);
        if (!file_exists($playlist)) {
            Log::warning("[Push] {$channel->name}: playlist not ready ({$playlist})");
            return false;
        }

        $this->stopPrimary($channel);

        $pid = $this->launchPush(
            $this->ffmpeg->buildPushCommand($channel, $playlist),
            $channel,
            'push'
        );

        if ($pid === null) {
            $channel->update(['push_pid' => null, 'push_status' => 'error']);
            return false;
        }

        $channel->update(['push_pid' => $pid, 'push_status' => 'live']);
        Log::info("[Push] {$channel->name} started — PID {$pid} — reading {$playlist}");

        // Start all enabled secondary destinations
        $this->startDestinations($channel, $playlist);

        return true;
    }

    public function stop(Channel $channel): void
    {
        $this->stopDestinations($channel);
        $this->stopPrimary($channel);
        $channel->update(['push_status' => 'stopped']);
    }

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'push'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    public function startDvrPlayback(Channel $channel): bool
    {
        $concat = $channel->dvr_directory . '/concat.txt';
        if (!file_exists($concat)) {
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
        if (!file_exists($playlist)) return;

        foreach ($channel->pushDestinations()->where('enabled', true)->get() as $dest) {
            if ($dest->pid && $this->ffmpeg->isRunning((int) $dest->pid)) continue;

            $pushUrl = $this->buildDestinationUrl($dest);
            $cmd = $this->ffmpeg->buildPushCommand($channel, $playlist);

            // Replace the URL in the command with the destination's URL
            $cmd[count($cmd) - 1] = $pushUrl;

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
            if ($dest->pid && !$this->ffmpeg->isRunning((int) $dest->pid)) {
                Log::warning("[Push] {$channel->name} → {$dest->name} died — restarting");
                $pushUrl = $this->buildDestinationUrl($dest);
                $cmd = $this->ffmpeg->buildPushCommand($channel, $playlist);
                $cmd[count($cmd) - 1] = $pushUrl;

                $pid = $this->launchPush($cmd, $channel, "push_dest_{$dest->id}");
                if ($pid !== null) {
                    $dest->update(['pid' => $pid, 'status' => 'live', 'last_active_at' => now()]);
                } else {
                    $dest->update(['status' => 'error']);
                }
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
        $pid     = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) $this->ffmpeg->stopProcess($pid);
        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['push_pid' => null]);
    }

    private function buildDestinationUrl(PushDestination $dest): string
    {
        $target = rtrim($dest->url, '/') . '/' . $dest->stream_key;

        if ($dest->protocol === 'srt') {
            $latency = config('skymedia.srt_latency', 200) * 1000;
            $base    = preg_replace('#^srt://#', '', $target);
            $query   = "latency={$latency}&mode=caller";
            if ($dest->username) $query .= '&username=' . urlencode($dest->username);
            if ($dest->password) $query .= '&passphrase=' . urlencode($dest->password);
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
