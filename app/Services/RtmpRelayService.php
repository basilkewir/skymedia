<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RtmpRelayService
 *
 * Receives an incoming RTMP stream pushed by an encoder and immediately
 * relays it to the channel's configured push destination.
 *
 * Architecture:
 *   Encoder → ffmpeg -listen (RTMP server) → ffmpeg push → external RTMP server
 *
 * ffmpeg's RTMP server mode (-listen 1) binds to a local port and accepts
 * exactly one incoming connection, then relays it in real-time.
 *
 * Each channel gets a unique stream key. The relay listens on a dedicated
 * port derived from the channel ID to avoid conflicts.
 *
 * Ingest URL shown to the encoder:
 *   rtmp://<server-ip>:<port>/live/<stream-key>
 */
class RtmpRelayService
{
    // Base port — channel 1 = 19351, channel 2 = 19352, etc.
    private const BASE_PORT = 19350;

    public function __construct(protected FFmpegService $ffmpeg) {}

    public function start(Channel $channel): bool
    {
        if ($this->isRunning($channel)) {
            return true;
        }

        $this->stop($channel);

        $port    = $this->portFor($channel);
        $key     = $channel->rtmp_input_key;
        $pushUrl = $channel->push_target;

        if (empty($key) || empty($pushUrl)) {
            Log::warning("[Relay] {$channel->name}: missing stream key or push URL");
            return false;
        }

        $listenUrl = "rtmp://0.0.0.0:{$port}/live/{$key}";

        $cmd = [
            $this->ffmpeg->getBin(),
            '-y', '-loglevel', 'warning', '-stats',
            '-listen',  '1',
            '-timeout', '30000000',   // 30s wait for encoder to connect
            '-i',       $listenUrl,
            '-c:v', 'copy',
            '-c:a', 'copy',
            '-f',   'flv',
            '-flvflags', 'no_duration_filesize',
            $pushUrl,
        ];

        $pidFile = $this->ffmpeg->pidFile($channel, 'relay');
        $logFile = $this->ffmpeg->logFile($channel, 'relay');

        try {
            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile, 5);
            $channel->update(['relay_pid' => $pid, 'stream_status' => 'live', 'push_status' => 'live']);
            Log::info("[Relay] {$channel->name} started — PID {$pid} — listening on :{$port}/{$key}");
            return true;
        } catch (\Throwable $e) {
            Log::error("[Relay] {$channel->name} failed: {$e->getMessage()}");
            $channel->update(['relay_pid' => null, 'stream_status' => 'error', 'last_error' => substr($e->getMessage(), 0, 500)]);
            return false;
        }
    }

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'relay');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
            Log::info("[Relay] {$channel->name} stopped — PID {$pid}");
        }

        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['relay_pid' => null]);
    }

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'relay'));
        return $pid > 0 && $this->ffmpeg->isRunning($pid);
    }

    public function portFor(Channel $channel): int
    {
        return self::BASE_PORT + $channel->id;
    }

    /**
     * Generate a random stream key for a channel.
     */
    public static function generateKey(): string
    {
        return Str::random(24);
    }

    public function ingestUrl(Channel $channel): string
    {
        $serverIp = config('skymedia.server_ip', request()->getHost());
        return "rtmp://{$serverIp}:{$this->portFor($channel)}/live/{$channel->rtmp_input_key}";
    }
}
