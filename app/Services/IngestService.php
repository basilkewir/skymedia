<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;

class IngestService
{
    public function __construct(protected FFmpegService $ffmpeg) {}

    /**
     * Start the ingest ffmpeg process.
     *
     * @param bool $cleanSegments Whether to clean stale segments before starting.
     *        Pass false when restarting a listener for a push-ingest channel
     *        (to avoid breaking the push process reading live.m3u8).
     * @throws \RuntimeException with the ffmpeg stderr on failure
     */
    public function start(Channel $channel, bool $cleanSegments = true): bool
    {
        // Kill any existing process (tracked or orphaned).
        if ($this->isRunning($channel)) {
            $this->stop($channel);
        } else {
            $pidFile = $this->ffmpeg->pidFile($channel, 'ingest');
            $this->ffmpeg->clearPid($pidFile);
            $channel->update(['pid' => null]);
        }

        // For push-ingest channels, also kill any orphan process holding the
        // port that wasn't tracked by the PID file, then wait for the port to
        // be fully released before binding a new listener. Without this wait,
        // the new ffmpeg gets "Address already in use" and fails immediately.
        if ($channel->isPushIngest() && $channel->ingest_port) {
            $this->killPortOrphan($channel->ingest_port);
            $this->waitForPortFree($channel->ingest_port, 8);
        }

        $dvrDir = $channel->dvr_directory;
        if (!is_dir($dvrDir)) {
            if (!mkdir($dvrDir, 0755, true) && !is_dir($dvrDir)) {
                throw new \RuntimeException("Cannot create DVR directory: {$dvrDir}");
            }
        }

        // Remove stale ingest segments + playlist so a fresh start does not
        // inherit stale files from the previous run.  Does NOT touch playout
        // files (playout_*.ts / playout.m3u8 / playout_concat.txt / slate),
        // so fallback playback continues uninterrupted while ingest waits.
        // Skip when restarting listener for push-ingest (encoder offline) to
        // avoid breaking the push process reading live.m3u8 via output.m3u8.
        if ($cleanSegments) {
            $this->cleanSegments($dvrDir);
        }

        // Verify ffmpeg binary exists and is executable
        $bin     = $this->ffmpeg->getBin();
        $binPath = trim((string) shell_exec("which {$bin} 2>/dev/null"))
                   ?: trim((string) shell_exec("command -v {$bin} 2>/dev/null"));

        if (empty($binPath)) {
            throw new \RuntimeException(
                "ffmpeg binary not found in PATH. Configured as: '{$bin}'. "
                . "Run: which ffmpeg   or set FFMPEG_BINARY=/full/path in .env"
            );
        }

        $cmd     = $this->ffmpeg->buildIngestCommand($channel);
        $pidFile = $this->ffmpeg->pidFile($channel, 'ingest');
        $logFile = $this->ffmpeg->logFile($channel, 'ingest');

        // Push-ingest listeners bind the port immediately and then wait for
        // an encoder connection — use a shorter stabilise window so the port
        // is available again as fast as possible after a reconnect.
        $stabilise = $channel->isPushIngest() ? 1 : 3;
        $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile, $stabilise);

        $waitingForPush = $channel->isPushIngest();
        $channel->update([
            'pid'           => $pid,
            'stream_status' => $waitingForPush ? 'starting' : 'live',
            'dvr_status'    => $channel->isPushIngest() || $channel->dvr_enabled === false
                ? 'idle'
                : ($waitingForPush ? 'starting' : 'recording'),
            'source_live'   => ! $waitingForPush,
            'last_live_at'  => $waitingForPush ? $channel->last_live_at : now(),
            'retry_count'   => 0,
            'last_error'    => null,
        ]);

        Log::info("[Ingest] {$channel->name} started — PID {$pid} — DVR: {$dvrDir}");
        return true;
    }

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'ingest');
        $pid     = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
            Log::info("[Ingest] {$channel->name} stopped — PID {$pid}");
        }

        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['pid' => null]);
    }

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'ingest'));
        if ($pid > 0 && $this->ffmpeg->isRunning($pid)) {
            return true;
        }

        // For push-ingest channels the PID file may be missing (e.g. after a
        // container restart) while an ffmpeg listener still holds the port.
        // Detect this by checking ss/netstat and adopt the running PID so the
        // monitor stops trying to restart it.
        if ($channel->isPushIngest() && $channel->ingest_port) {
            $port = (int) $channel->ingest_port;
            $out  = shell_exec("ss -tlnp 2>/dev/null | grep :{$port} | grep -o 'pid=[0-9]*' | head -1");
            if (! $out) {
                $out = shell_exec("ss -ulnp 2>/dev/null | grep :{$port} | grep -o 'pid=[0-9]*' | head -1");
            }
            if ($out && preg_match('/pid=(\d+)/', trim($out), $m)) {
                $livePid = (int) $m[1];
                if ($livePid > 0 && $this->ffmpeg->isRunning($livePid)) {
                    // Adopt: write the PID file so future checks work normally.
                    file_put_contents($this->ffmpeg->pidFile($channel, 'ingest'), $livePid);
                    $channel->update(['pid' => $livePid]);
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Kill any process (not tracked by PID file) that is holding the given port.
     * This handles the case where ffmpeg exited but the OS hasn't released the
     * port yet, or a zombie process is still listed in /proc.
     */
    protected function killPortOrphan(int $port): void
    {
        // Try ss first (faster), fall back to fuser
        $out = shell_exec("ss -tlnp 2>/dev/null | grep :{$port} | grep -o 'pid=[0-9]*' | head -1");
        if (!$out) {
            $out = shell_exec("ss -ulnp 2>/dev/null | grep :{$port} | grep -o 'pid=[0-9]*' | head -1");
        }
        if ($out && preg_match('/pid=(\d+)/', trim($out), $m)) {
            $pid = (int) $m[1];
            if ($pid > 0) {
                $this->ffmpeg->stopProcess($pid, 4);
                Log::info("[Ingest] Killed orphan PID {$pid} holding port {$port}");
            }
            return;
        }
        // fuser fallback
        $fuser = trim((string) shell_exec("fuser {$port}/tcp 2>/dev/null"));
        if ($fuser) {
            foreach (preg_split('/\s+/', $fuser) as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) {
                    $this->ffmpeg->stopProcess($pid, 4);
                    Log::info("[Ingest] Killed orphan PID {$pid} holding port {$port} (fuser)");
                }
            }
        }
    }

    /**
     * Wait up to $maxSeconds for the port to stop appearing in ss/netstat.
     * Returns true when the port is free, false on timeout.
     */
    protected function waitForPortFree(int $port, int $maxSeconds = 8): bool
    {
        $deadline = time() + $maxSeconds;
        while (time() < $deadline) {
            $inUse = shell_exec("ss -tlnp 2>/dev/null | grep :{$port}") ?:
                     shell_exec("ss -ulnp 2>/dev/null | grep :{$port}");
            if (!trim((string) $inUse)) {
                return true;
            }
            usleep(200_000); // 200ms
        }
        Log::warning("[Ingest] Port {$port} still in use after {$maxSeconds}s — proceeding anyway");
        return false;
    }

    /**
     * Remove stale ingest segments and playlist so ffmpeg starts with a clean slate.
     * Does NOT touch playout files (playout_*.ts, playout.m3u8, playout_concat.txt,
     * slate.mp4, fallback_loop.mp4, recordings) — those belong to PlayoutService.
     */
    protected function cleanSegments(string $dvrDir): void
    {
        if (! is_dir($dvrDir)) return;

        foreach (glob("{$dvrDir}/seg_*.ts") ?: [] as $f) @unlink($f);
        @unlink("{$dvrDir}/live.m3u8");
        // Never remove output.m3u8 here: it may still point at a healthy VOD
        // fallback while a returning live source is warming up.
    }
}
