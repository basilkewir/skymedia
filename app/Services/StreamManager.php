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

        // Kill any stale processes
        $this->killLive($channel);

        $this->log($channel, 'info', 'stream_starting', 'Starting live ingest', 'source');
        $channel->update(['stream_status' => 'starting', 'push_status' => 'connecting', 'dvr_status' => 'starting']);

        try {
            $dvrDir = $channel->dvr_directory;
            if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

            $cmd     = $this->ffmpeg->buildLiveCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'live');
            $logFile = $this->ffmpeg->logFile($channel, 'live');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

            if ($pid <= 0) throw new \RuntimeException('ffmpeg process did not start');

            $channel->update([
                'pid'           => $pid,
                'stream_status' => 'live',
                'push_status'   => 'pushing',
                'dvr_status'    => 'recording',
                'source_live'   => true,
                'last_live_at'  => now(),
                'last_check_at' => now(),
            ]);

            $this->log($channel, 'info', 'stream_started',  "Source ingest started (PID {$pid})", 'source');
            $this->log($channel, 'info', 'dvr_recording',   'DVR recording started', 'dvr');
            $this->log($channel, 'info', 'push_started',    "Pushing to {$channel->push_protocol}://{$channel->push_url}", 'push');
            event(new StreamStatusChanged($channel, 'live'));
            return true;

        } catch (\Throwable $e) {
            $channel->update(['stream_status' => 'error', 'push_status' => 'error', 'dvr_status' => 'error']);
            $this->log($channel, 'error', 'stream_start_failed', $e->getMessage(), 'source');
            Log::error("[Channel {$channel->id}] start failed: {$e->getMessage()}");
            return false;
        }
    }

    public function stopChannel(Channel $channel): bool
    {
        $this->killLive($channel);
        $this->killDvr($channel);

        $channel->update([
            'pid'           => null,
            'dvr_pid'       => null,
            'stream_status' => 'stopped',
            'push_status'   => 'idle',
            'dvr_status'    => 'idle',
            'is_active'     => false,
            'source_live'   => false,
        ]);

        $this->log($channel, 'info', 'stream_stopped',  'Source ingest stopped', 'source');
        $this->log($channel, 'info', 'push_stopped',    'Push output stopped', 'push');
        $this->log($channel, 'info', 'dvr_stopped',     'DVR recording stopped', 'dvr');
        event(new StreamStatusChanged($channel, 'stopped'));
        return true;
    }

    // ---------------------------------------------------------------
    // Monitor tick — called every N seconds by the daemon command
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
        $this->log($channel, 'warning', 'source_lost',   'Source stream went offline', 'source');
        $this->log($channel, 'warning', 'dvr_switching', 'DVR recording paused — switching to DVR playback', 'dvr');

        $this->killLive($channel);
        $channel->update(['source_live' => false, 'pid' => null, 'stream_status' => 'offline', 'dvr_status' => 'idle']);

        if ($this->dvr->hasSegments($channel)) {
            $this->log($channel, 'info', 'push_dvr_fallback', 'Push switching to DVR playback source', 'push');
            $this->startDvrPlayback($channel);
        } else {
            $this->log($channel, 'error', 'no_dvr',       'No DVR segments available — push on hold', 'dvr');
            $this->log($channel, 'error', 'push_stalled', 'Push stalled — no source or DVR available', 'push');
            $channel->update(['stream_status' => 'error', 'push_status' => 'error', 'dvr_status' => 'error']);
            event(new StreamStatusChanged($channel, 'error'));
        }
    }

    protected function onSourceRecovered(Channel $channel): void
    {
        $this->log($channel, 'info', 'source_recovered',   'Source stream back online', 'source');
        $this->log($channel, 'info', 'push_live_resume',   'Push switching back to live source', 'push');
        $this->log($channel, 'info', 'dvr_resume',         'DVR recording resuming', 'dvr');

        $this->killDvr($channel);
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
        $livePid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'live'));

        if ($livePid > 0 && $this->ffmpeg->isRunning($livePid)) {
            // Process healthy — sync DVR segments + enforce window
            $this->dvr->syncSegments($channel);

            if ($channel->stream_status !== 'live') {
                $channel->update(['stream_status' => 'live', 'source_live' => true]);
            }
        } else {
            // ffmpeg died unexpectedly — restart
            $this->log($channel, 'warning', 'process_died',   'Source ingest process died — restarting', 'source');
            $this->log($channel, 'warning', 'push_reconnect', 'Push reconnecting after process restart', 'push');
            $this->startChannel($channel);
        }
    }

    protected function onSourceStillDown(Channel $channel): void
    {
        $dvrPid = $this->ffmpeg->readPid($this->ffmpeg->pidFile($channel, 'dvr'));

        if ($dvrPid > 0 && $this->ffmpeg->isRunning($dvrPid)) {
            // DVR playback running fine — rebuild concat to pick up newest segments
            if ($this->dvr->buildConcatFile($channel)) {
                if ($channel->stream_status !== 'dvr_playback') {
                    $channel->update(['stream_status' => 'dvr_playback']);
                }
            }
        } else {
            // DVR process not running — (re)start it if we have segments
            if ($this->dvr->hasSegments($channel)) {
                $this->log($channel, 'warning', 'dvr_restart', 'DVR process not running — restarting DVR playback');
                $this->startDvrPlayback($channel);
            } else {
                $channel->update(['stream_status' => 'error']);
            }
        }
    }

    // ---------------------------------------------------------------
    // DVR playback
    // ---------------------------------------------------------------

    protected function startDvrPlayback(Channel $channel): void
    {
        try {
            if (!$this->dvr->buildConcatFile($channel)) {
                throw new \RuntimeException('No DVR segments to play back');
            }

            $cmd     = $this->ffmpeg->buildDvrPlaybackCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'dvr');
            $logFile = $this->ffmpeg->logFile($channel, 'dvr');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

            if ($pid <= 0) throw new \RuntimeException('DVR ffmpeg process did not start');

            $channel->update([
                'dvr_pid'       => $pid,
                'stream_status' => 'dvr_playback',
                'dvr_status'    => 'playing',
                'push_status'   => 'pushing',
            ]);

            $this->log($channel, 'info', 'dvr_playback_started', "DVR playback started (PID {$pid})", 'dvr');
            $this->log($channel, 'info', 'push_dvr_active',      'Push now streaming from DVR', 'push');
            event(new StreamStatusChanged($channel, 'dvr_playback'));

        } catch (\Throwable $e) {
            $channel->update(['stream_status' => 'error', 'dvr_status' => 'error', 'push_status' => 'error']);
            $this->log($channel, 'error', 'dvr_playback_failed', $e->getMessage(), 'dvr');
        }
    }

    // ---------------------------------------------------------------
    // Process helpers
    // ---------------------------------------------------------------

    protected function killLive(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'live');
        $pid     = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }
        $this->ffmpeg->clearPid($pidFile);
    }

    protected function killDvr(Channel $channel): void
    {
        $pidFile = $this->ffmpeg->pidFile($channel, 'dvr');
        $pid     = $this->ffmpeg->readPid($pidFile);
        if ($pid > 0) {
            $this->ffmpeg->stopProcess($pid);
        }
        $this->ffmpeg->clearPid($pidFile);
    }

    // ---------------------------------------------------------------
    // Logging helper
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
