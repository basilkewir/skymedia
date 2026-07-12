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
     * @param  bool  $cleanSegments  Whether to clean stale segments before starting.
     *                               Pass false when restarting a listener for a push-ingest channel
     *                               (to avoid breaking the push process reading live.m3u8).
     *
     * @throws \RuntimeException with the ffmpeg stderr on failure
     */
    public function start(Channel $channel, bool $cleanSegments = true): bool
    {
        // For push-ingest channels, use the full stopAllListeners routine so
        // stale loop shells (which respawn ffmpeg immediately) are removed
        // before we try to bind the port again.
        if ($channel->isPushIngest() && $channel->ingest_port) {
            $this->stopAllListeners($channel);
        } elseif ($this->isRunning($channel)) {
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
        if (! is_dir($dvrDir)) {
            if (! mkdir($dvrDir, 0755, true) && ! is_dir($dvrDir)) {
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
        $bin = $this->ffmpeg->getBin();
        $binPath = trim((string) shell_exec("which {$bin} 2>/dev/null"))
                   ?: trim((string) shell_exec("command -v {$bin} 2>/dev/null"));

        if (empty($binPath)) {
            throw new \RuntimeException(
                "ffmpeg binary not found in PATH. Configured as: '{$bin}'. "
                . 'Run: which ffmpeg   or set FFMPEG_BINARY=/full/path in .env'
            );
        }

        $cmd = $this->ffmpeg->buildIngestCommand($channel);
        $pidFile = $this->ffmpeg->pidFile($channel, 'ingest');
        $logFile = $this->ffmpeg->logFile($channel, 'ingest');

        // Push-ingest listeners bind the port immediately and then wait for
        // an encoder connection — use a shorter stabilise window so the port
        // is available again as fast as possible after a reconnect.
        $stabilise = $channel->isPushIngest() ? 1 : 3;
        $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile, $stabilise);

        // Determining whether the source is *actually* live requires waiting for
        // the first HLS segments to land on disk. Prematurely marking the
        // channel as live here (the previous behaviour for pull ingest) is the
        // root cause of the "stuck on fallback with source_live=1" symptom:
        // ffmpeg would start, hold the port for ~3s, then exit when the remote
        // returned 5xx/timeout — by which time the DB already claimed the
        // source was live, and the monitor's stale `source_live` flag kept
        // the channel out of the auto-recovery loop.
        //
        // We now start in a neutral "starting" state for both push and pull
        // ingest. The monitor (StreamManager::monitorChannel) is the *only*
        // authority that promotes the channel to `live` — and it does so on
        // segment evidence (hasRecentSegments / liveHlsReady) via
        // onSourceRecovered / onSourceStillLive.
        $waitingForPush = $channel->isPushIngest();
        $channel->update([
            'pid' => $pid,
            'stream_status' => 'starting',
            'dvr_status' => ($waitingForPush || $channel->dvr_enabled === false) ? 'idle' : 'starting',
            // Never declare live here; the monitor decides on segment evidence.
            'source_live' => false,
            // Preserve last_live_at — it tracks the last confirmed-live moment,
            // not the last "we tried to start ffmpeg" moment.
            'last_live_at' => $channel->last_live_at,
            'retry_count' => 0,
            'last_error' => null,
        ]);

        Log::info("[Ingest] {$channel->name} started — PID {$pid} — DVR: {$dvrDir}");

        return true;
    }

    public function stop(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'ingest');
        $pid = $this->ffmpeg->readPid($pidFile);

        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
            Log::info("[Ingest] {$channel->name} stopped — PID {$pid}");
        }

        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['pid' => null]);
    }

    /**
     * Kill ALL orphan ffmpeg ingest listeners for this channel.
     *
     * When the encoder disconnects from a `-listen 1` RTMP listener, the old
     * ffmpeg process may linger (waiting for the 120 s timeout) while our code
     * starts a new one — stacking multiple listeners on the same port.  This
     * method uses `pkill` to find and kill every ffmpeg process that references
     * this channel's ingest port, ensuring a clean slate before starting a
     * fresh listener.
     */
    public function stopAllListeners(Channel $channel): int
    {
        // Extract port from ingest_listen_url
        $url = $channel->ingest_listen_url ?? '';
        if (preg_match('/:(\d+)\//', $url, $m)) {
            $port = $m[1];
        } else {
            $port = 20000 + $channel->id;
        }

        $pidFile = $this->ffmpeg->pidFile($channel, 'ingest');
        $stopFile = $pidFile . '.stop';
        $listenUrl = $channel->ingest_listen_url ?? '';

        // Write the sentinel stop file FIRST so loops exit cleanly instead of
        // respawning ffmpeg after we kill the current child.
        @touch($stopFile);

        $totalKilled = 0;
        $loopPidsKilled = [];

        // Repeatedly kill every loop shell and ffmpeg child for this channel
        // until none remain. A single pass is not enough because stale loops
        // from previous hangs/restarts can respawn faster than we clean them.
        for ($pass = 0; $pass < 5; $pass++) {
            $killedThisPass = 0;

            // 1. Find all loop shells that reference this channel's listen URL.
            $loopLines = [];
            if ($listenUrl !== '') {
                exec('ps aux | grep -F ' . escapeshellarg($listenUrl) . " | grep -F 'while' | grep -v grep | awk '{print \$2}' 2>/dev/null", $loopLines);
            }

            foreach ($loopLines as $line) {
                $pid = (int) trim($line);
                if ($pid > 0 && ! in_array($pid, $loopPidsKilled, true)) {
                    exec("pkill -KILL -P {$pid} 2>/dev/null"); // ffmpeg child first
                    exec("kill -KILL {$pid} 2>/dev/null");     // loop shell
                    $loopPidsKilled[] = $pid;
                    $killedThisPass++;
                }
            }

            // 2. Kill any remaining ffmpeg processes bound to this port.
            $ffmpegLines = [];
            exec("ps aux | grep -F '0.0.0.0:{$port}' | grep -v grep | awk '{print \$2}' 2>/dev/null", $ffmpegLines);
            foreach ($ffmpegLines as $line) {
                $pid = (int) trim($line);
                if ($pid > 0) {
                    exec("kill -KILL {$pid} 2>/dev/null");
                    $killedThisPass++;
                }
            }

            $totalKilled += $killedThisPass;

            if ($killedThisPass === 0) {
                break;
            }

            usleep(200_000); // brief pause for kernel to reap processes
        }

        $this->ffmpeg->clearPid($pidFile); // also removes .stop file
        $channel->update(['pid' => null]);

        if ($totalKilled > 0) {
            $loopList = $loopPidsKilled ? implode(',', $loopPidsKilled) : 'none';
            Log::info("[Ingest] {$channel->name} stopped listener loops (PIDs {$loopList}) + {$totalKilled} orphan(s) on port {$port}");
        }

        usleep(300_000); // 300ms for kernel to release port

        return $totalKilled;
    }

    public function isRunning(Channel $channel): bool
    {
        $pid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'ingest'));
        if ($pid > 0 && $this->ffmpeg->isRunning($pid)) {
            return true;
        }

        // For push-ingest channels the loop wrapper may have restarted ffmpeg
        // with a new child PID. Check if the port is still bound.
        if ($channel->isPushIngest() && $channel->ingest_port) {
            $port = (int) $channel->ingest_port;
            $hexPort = strtoupper(dechex($port));
            $tcpContent = @file_get_contents('/proc/net/tcp');
            $tcp6Content = @file_get_contents('/proc/net/tcp6');
            if (($tcpContent !== false && str_contains($tcpContent, ":{$hexPort} "))
             || ($tcp6Content !== false && str_contains($tcp6Content, ":{$hexPort} "))) {
                return true;
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
        // Check if port is in use via /proc/net/tcp (works inside Docker)
        $hexPort = strtoupper(dechex($port));
        $tcpContent = @file_get_contents('/proc/net/tcp');
        $tcp6Content = @file_get_contents('/proc/net/tcp6');
        $portInUse = ($tcpContent !== false && str_contains($tcpContent, ":{$hexPort} "))
                  || ($tcp6Content !== false && str_contains($tcp6Content, ":{$hexPort} "));

        if (! $portInUse) {
            return;
        }

        // Try fuser to get the PID
        $fuser = trim((string) shell_exec("fuser {$port}/tcp 2>/dev/null"));
        if ($fuser) {
            foreach (preg_split('/\s+/', $fuser) as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) {
                    $this->ffmpeg->stopProcess($pid, 4);
                    Log::info("[Ingest] Killed orphan PID {$pid} holding port {$port} (fuser)");
                }
            }

            return;
        }

        // Last resort: find ffmpeg processes that reference this port
        $grep = trim((string) shell_exec("ps aux 2>/dev/null | grep ':{$port}' | grep -v grep"));
        if ($grep) {
            if (preg_match('/^\S+\s+(\d+)\s/', $grep, $m)) {
                $pid = (int) $m[1];
                if ($pid > 0) {
                    $this->ffmpeg->stopProcess($pid, 4);
                    Log::info("[Ingest] Killed orphan PID {$pid} holding port {$port} (ps grep)");
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
        $hexPort = strtoupper(dechex($port));
        $deadline = time() + $maxSeconds;
        while (time() < $deadline) {
            $tcpContent = @file_get_contents('/proc/net/tcp');
            $tcp6Content = @file_get_contents('/proc/net/tcp6');
            $inUse = ($tcpContent !== false && str_contains($tcpContent, ":{$hexPort} "))
                   || ($tcp6Content !== false && str_contains($tcp6Content, ":{$hexPort} "));
            if (! $inUse) {
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
        if (! is_dir($dvrDir)) {
            return;
        }

        foreach (glob("{$dvrDir}/seg_*.ts") ?: [] as $f) {
            @unlink($f);
        }
        @unlink("{$dvrDir}/live.m3u8");
        // Never remove output.m3u8 here: it may still point at a healthy VOD
        // fallback while a returning live source is warming up.
    }
}
