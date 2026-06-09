<?php

namespace App\Services;

use App\Events\StreamStatusChanged;
use App\Models\Channel;
use App\Models\StreamLog;
use Illuminate\Support\Facades\Log;

class StreamManager
{
    public function __construct(
        protected FFmpegService $ffmpeg,
        protected DVRService    $dvr,
    ) {}

    // ---------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------

    public function startChannel(Channel $channel): bool
    {
        if (!$channel->is_active) {
            $channel->update(['is_active' => true]);
        }

        $this->killAll($channel);

        $this->log($channel, 'info', 'stream_starting', 'Starting stream channel', 'source');
        $channel->update([
            'stream_status' => 'starting',
            'push_status'   => 'connecting',
            'dvr_status'    => 'starting',
            'retry_count'   => 0,
            'last_error'    => null,
        ]);

        try {
            $dvrDir = $channel->dvr_directory;
            if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

            $success = $this->startDvrRecorder($channel);
            if (!$success) {
                throw new \RuntimeException('DVR recorder failed to start');
            }

            $success = $this->startPushEngine($channel);
            if (!$success) {
                $this->killDvrRecorder($channel);
                throw new \RuntimeException('Push engine failed to start');
            }

            $channel->update([
                'stream_status' => 'live',
                'push_status'   => 'pushing',
                'dvr_status'    => 'recording',
                'source_live'   => true,
                'last_live_at'  => now(),
                'last_check_at' => now(),
            ]);

            $this->log($channel, 'info', 'stream_started',  "Stream live (DVR PID {$channel->pid}, Push PID {$channel->push_pid})", 'source');
            $this->log($channel, 'info', 'dvr_recording',   'DVR recording started', 'dvr');
            $this->log($channel, 'info', 'push_started',    "Pushing to {$channel->push_protocol}://{$channel->push_url}", 'push');
            event(new StreamStatusChanged($channel, 'live'));
            return true;

        } catch (\Throwable $e) {
            $maxed = $channel->incrementRetry($e->getMessage());
            $channel->update([
                'stream_status' => 'error',
                'push_status'   => 'error',
                'dvr_status'    => 'error',
                'source_live'   => false,
            ]);
            $this->log($channel, 'error', 'stream_start_failed', $e->getMessage(), 'source');
            Log::error("[Channel {$channel->id}] start failed: {$e->getMessage()}");
            return false;
        }
    }

    public function stopChannel(Channel $channel): bool
    {
        $this->killAll($channel);

        $channel->update([
            'pid'           => null,
            'dvr_pid'       => null,
            'push_pid'      => null,
            'stream_status' => 'stopped',
            'push_status'   => 'idle',
            'dvr_status'    => 'idle',
            'is_active'     => false,
            'source_live'   => false,
        ]);

        $this->log($channel, 'info', 'stream_stopped', 'Stream stopped', 'source');
        $this->log($channel, 'info', 'push_stopped',   'Push stopped', 'push');
        $this->log($channel, 'info', 'dvr_stopped',    'DVR stopped', 'dvr');
        event(new StreamStatusChanged($channel, 'stopped'));
        return true;
    }

    // ---------------------------------------------------------------
    // Monitor tick — called by daemon
    // ---------------------------------------------------------------

    public function monitorChannel(Channel $channel): void
    {
        if (!$channel->is_active) return;

        $channel->update(['last_check_at' => now()]);
        $sourceLive = $this->ffmpeg->checkSourceHealth($channel);

        if ($sourceLive && !$channel->source_live) {
            $this->onSourceRecovered($channel);
        } elseif (!$sourceLive && $channel->source_live) {
            $this->onSourceLost($channel);
        } elseif ($sourceLive) {
            $this->onSourceStillLive($channel);
        } else {
            $this->onSourceStillDown($channel);
        }
    }

    public function activateAll(): void
    {
        Channel::where('is_active', true)->each(function (Channel $c) {
            if (in_array($c->stream_status, ['idle', 'stopped', 'error'])) {
                $this->startChannel($c);
            }
        });
    }

    // ---------------------------------------------------------------
    // State transitions
    // ---------------------------------------------------------------

    protected function onSourceLost(Channel $channel): void
    {
        $this->log($channel, 'warning', 'source_lost', 'Source stream went offline', 'source');
        $this->log($channel, 'info', 'dvr_paused', 'DVR recording paused', 'dvr');

        $this->killDvrRecorder($channel);
        $this->killPushEngine($channel);

        $channel->update([
            'source_live' => false,
            'pid'         => null,
            'push_pid'    => null,
            'dvr_status'  => 'idle',
        ]);

        if ($this->dvr->hasSegments($channel)) {
            $this->dvr->buildConcatFile($channel);
            $this->log($channel, 'info', 'push_dvr_fallback', 'Switching push to DVR playback', 'push');
            $this->startDvrPlayback($channel);
        } else {
            $this->log($channel, 'error', 'no_dvr', 'No DVR segments available', 'dvr');
            $this->log($channel, 'error', 'push_stalled', 'No source or DVR available — push idle', 'push');
            $channel->update([
                'stream_status' => 'offline',
                'push_status'   => 'idle',
            ]);
            event(new StreamStatusChanged($channel, 'offline'));
        }
    }

    protected function onSourceRecovered(Channel $channel): void
    {
        $this->log($channel, 'info', 'source_recovered', 'Source stream back online', 'source');
        $this->log($channel, 'info', 'push_live_resume', 'Switching push back to live source', 'push');
        $this->log($channel, 'info', 'dvr_resume', 'DVR recording resuming', 'dvr');

        $this->killDvrPlayback($channel);

        $channel->update([
            'source_live'  => true,
            'dvr_pid'      => null,
            'dvr_status'   => 'starting',
            'last_live_at' => now(),
        ]);

        $this->startChannel($channel);
    }

    protected function onSourceStillLive(Channel $channel): void
    {
        $livePid  = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'live'));
        $pushPid  = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'push'));

        $liveRunning  = $livePid > 0 && $this->ffmpeg->isRunning($livePid);
        $pushRunning  = $pushPid > 0 && $this->ffmpeg->isRunning($pushPid);

        if ($liveRunning && $pushRunning) {
            $this->dvr->syncSegments($channel);

            if ($channel->stream_status !== 'live') {
                $channel->update(['stream_status' => 'live', 'source_live' => true]);
            }
            $channel->resetRetries();
        } elseif (!$liveRunning && !$pushRunning) {
            $this->log($channel, 'warning', 'process_died', 'Both DVR recorder and push died — restarting', 'source');
            $this->startChannel($channel);
        } elseif (!$liveRunning) {
            $this->log($channel, 'warning', 'dvr_died', 'DVR recorder died — restarting', 'dvr');
            $this->killDvrRecorder($channel);
            $this->startDvrRecorder($channel);
        } elseif (!$pushRunning) {
            $this->log($channel, 'warning', 'push_died', 'Push engine died — restarting', 'push');
            $this->killPushEngine($channel);
            $this->startPushEngine($channel);
        }
    }

    protected function onSourceStillDown(Channel $channel): void
    {
        $dvrPid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'dvr'));
        $dvrRunning = $dvrPid > 0 && $this->ffmpeg->isRunning($dvrPid);

        if ($dvrRunning) {
            $this->dvr->syncSegments($channel);
            $this->dvr->buildConcatFile($channel);

            if ($this->dvrNeedsRestart($channel)) {
                $this->log($channel, 'info', 'dvr_playback_refresh', 'New segments available — restarting DVR playback', 'dvr');
                $this->killDvrPlayback($channel);
                $this->startDvrPlayback($channel);
            }

            if ($channel->stream_status !== 'dvr_playback') {
                $channel->update(['stream_status' => 'dvr_playback', 'push_status' => 'pushing']);
            }
        } else {
            if ($this->dvr->hasSegments($channel)) {
                $this->dvr->buildConcatFile($channel);
                $this->log($channel, 'warning', 'dvr_restart', 'DVR playback not running — restarting', 'dvr');
                $this->startDvrPlayback($channel);
            } else {
                $maxed = $channel->incrementRetry('Source still offline after max retries');
                if ($maxed) {
                    $channel->update(['stream_status' => 'error', 'push_status' => 'error']);
                    $this->log($channel, 'critical', 'max_retries_reached', "Max retries ({$channel->max_retries}) reached", 'system');
                }
            }
        }
    }

    // ---------------------------------------------------------------
    // Process starters
    // ---------------------------------------------------------------

    protected function startDvrRecorder(Channel $channel): bool
    {
        try {
            $cmd     = $this->ffmpeg->buildDvrRecordCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'live');
            $logFile = $this->ffmpeg->logFile($channel, 'live');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

            if ($pid <= 0) {
                $logTail = $this->ffmpeg->readLogTail($logFile);
                throw new \RuntimeException("ffmpeg DVR process did not start. Log: {$logTail}");
            }

            $channel->update(['pid' => $pid, 'dvr_status' => 'recording']);
            $this->log($channel, 'info', 'dvr_recorder_started', "DVR recorder started (PID {$pid})", 'dvr');
            return true;
        } catch (\Throwable $e) {
            $this->log($channel, 'error', 'dvr_recorder_failed', $e->getMessage(), 'dvr');
            Log::error("[Channel {$channel->id}] DVR recorder failed: {$e->getMessage()}");
            return false;
        }
    }

    protected function startPushEngine(Channel $channel): bool
    {
        try {
            $cmd     = $this->ffmpeg->buildPushCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'push');
            $logFile = $this->ffmpeg->logFile($channel, 'push');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

            if ($pid <= 0) {
                $logTail = $this->ffmpeg->readLogTail($logFile);
                throw new \RuntimeException("ffmpeg push process did not start. Log: {$logTail}");
            }

            $channel->update(['push_pid' => $pid, 'push_status' => 'pushing']);
            $this->log($channel, 'info', 'push_engine_started', "Push engine started (PID {$pid})", 'push');
            return true;
        } catch (\Throwable $e) {
            $this->log($channel, 'error', 'push_engine_failed', $e->getMessage(), 'push');
            Log::error("[Channel {$channel->id}] Push engine failed: {$e->getMessage()}");
            return false;
        }
    }

    protected function startDvrPlayback(Channel $channel): void
    {
        try {
            if (!$this->dvr->buildConcatFile($channel)) {
                throw new \RuntimeException('No DVR segments available for playback');
            }

            $cmd     = $this->ffmpeg->buildDvrPlaybackCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'dvr');
            $logFile = $this->ffmpeg->logFile($channel, 'dvr');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

            if ($pid <= 0) {
                $logTail = $this->ffmpeg->readLogTail($logFile);
                throw new \RuntimeException("DVR playback process did not start. Log: {$logTail}");
            }

            $channel->update([
                'dvr_pid'       => $pid,
                'stream_status' => 'dvr_playback',
                'dvr_status'    => 'playing',
                'push_status'   => 'pushing',
            ]);

            $this->log($channel, 'info', 'dvr_playback_started', "DVR playback started (PID {$pid})", 'dvr');
            $this->log($channel, 'info', 'push_dvr_active', 'Push streaming from DVR', 'push');
            event(new StreamStatusChanged($channel, 'dvr_playback'));

        } catch (\Throwable $e) {
            $channel->update([
                'stream_status' => 'error',
                'dvr_status'    => 'error',
                'push_status'   => 'error',
            ]);
            $this->log($channel, 'error', 'dvr_playback_failed', $e->getMessage(), 'dvr');
        }
    }

    // ---------------------------------------------------------------
    // Process killers
    // ---------------------------------------------------------------

    protected function killDvrRecorder(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'live');
        $pid     = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }
        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['pid' => null]);
    }

    protected function killPushEngine(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'push');
        $pid     = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }
        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['push_pid' => null]);
    }

    protected function killDvrPlayback(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'dvr');
        $pid     = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }
        $this->ffmpeg->clearPid($pidFile);
        $channel->update(['dvr_pid' => null]);
    }

    protected function killAll(Channel $channel): void
    {
        $this->killDvrRecorder($channel);
        $this->killPushEngine($channel);
        $this->killDvrPlayback($channel);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    protected function dvrNeedsRestart(Channel $channel): bool
    {
        $dvrPid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'dvr'));
        if ($dvrPid <= 0) return true;

        $startTime = filemtime($this->ffmpeg->pidFile($channel, 'dvr'));
        $elapsed   = time() - $startTime;

        return $elapsed > $channel->dvr_duration * 0.5;
    }

    // ---------------------------------------------------------------
    // Logging
    // ---------------------------------------------------------------

    protected function log(Channel $channel, string $level, string $event, string $message, string $category = 'system', ?array $meta = null): void
    {
        try {
            StreamLog::create([
                'channel_id' => $channel->id,
                'level'      => $level,
                'event'      => $event,
                'message'    => $message,
                'metadata'   => array_merge(['category' => $category], $meta ?? []),
            ]);
        } catch (\Throwable $e) {
            Log::error("StreamLog write failed: {$e->getMessage()}");
        }
    }
}
