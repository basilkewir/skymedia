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

        $this->log($channel, 'info', 'stream_starting', 'Starting live ingest');
        $channel->update(['stream_status' => 'starting']);

        try {
            $dvrDir = $channel->dvr_directory;
            if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);

            $cmd     = $this->ffmpeg->buildLiveCommand($channel);
            $pidFile = $this->ffmpeg->pidFile($channel, 'live');
            $logFile = $this->ffmpeg->logFile($channel, 'live');

            $pid = $this->ffmpeg->startProcess($cmd, $pidFile, $logFile);

            if ($pid <= 0) throw new \RuntimeException('ffmpeg process did not start');

            $channel->update([
                'pid'          => $pid,
                'stream_status'=> 'live',
                'source_live'  => true,
                'last_live_at' => now(),
                'last_check_at'=> now(),
            ]);

            $this->log($channel, 'info', 'stream_started', "Live ingest PID {$pid}");
            event(new StreamStatusChanged($channel, 'live'));
            return true;

        } catch (\Throwable $e) {
            $channel->update(['stream_status' => 'error']);
            $this->log($channel, 'error', 'stream_start_failed', $e->getMessage());
            Log::error("[Channel {$channel->id}] start failed: {$e->getMessage()}");
            return false;
        }
    }

    public function stopChannel(Channel $channel): bool
    {
        $this->killLive($channel);
        $this->killDvr($channel);

        $channel->update([
            'pid'          => null,
            'dvr_pid'      => null,
            'stream_status'=> 'stopped',
            'is_active'    => false,
            'source_live'  => false,
        ]);

        $this->log($channel, 'info', 'stream_stopped', 'Channel stopped by admin');
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
        $this->log($channel, 'warning', 'source_lost', 'Source stream went offline — switching to DVR');

        $this->killLive($channel);
        $channel->update(['source_live' => false, 'pid' => null]);

        if ($this->dvr->hasSegments($channel)) {
            $this->startDvrPlayback($channel);
        } else {
            $this->log($channel, 'error', 'no_dvr', 'No DVR segments available — waiting for source');
            $channel->update(['stream_status' => 'error']);
            event(new StreamStatusChanged($channel, 'error'));
        }
    }

    protected function onSourceRecovered(Channel $channel): void
    {
        $this->log($channel, 'info', 'source_recovered', 'Source stream back online — switching to live');

        $this->killDvr($channel);
        $channel->update([
            'source_live' => true,
            'dvr_pid'     => null,
            'last_live_at'=> now(),
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
            $this->log($channel, 'warning', 'process_died', 'Live ffmpeg died — restarting');
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
                'dvr_pid'      => $pid,
                'stream_status'=> 'dvr_playback',
            ]);

            $this->log($channel, 'info', 'dvr_playback_started', "DVR playback PID {$pid}");
            event(new StreamStatusChanged($channel, 'dvr_playback'));

        } catch (\Throwable $e) {
            $channel->update(['stream_status' => 'error']);
            $this->log($channel, 'error', 'dvr_playback_failed', $e->getMessage());
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

    protected function log(Channel $channel, string $level, string $event, string $message, ?array $meta = null): void
    {
        try {
            StreamLog::create([
                'channel_id' => $channel->id,
                'level'      => $level,
                'event'      => $event,
                'message'    => $message,
                'metadata'   => $meta,
            ]);
        } catch (\Throwable $e) {
            Log::error("StreamLog write failed: {$e->getMessage()}");
        }
    }
}
