<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Channel;
use App\Models\PushDestination;
use App\Services\FFmpegService;
use App\Services\PlayoutService;
use App\Services\PushService;
use Mockery;
use Tests\TestCase;

class PushServiceTest extends TestCase
{
    private PushService $push;
    private \Mockery\MockInterface $ffmpeg;
    private \Mockery\MockInterface $playout;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ffmpeg  = Mockery::mock(FFmpegService::class);
        $this->playout = Mockery::mock(PlayoutService::class);

        $this->push = new PushService($this->ffmpeg, $this->playout);

        $this->channel = Channel::create([
            'name'             => 'PushTest',
            'slug'             => 'push-test-' . fake()->randomNumber(4),
            'source_type'      => 'hls',
            'source_url'       => 'https://example.com/stream.m3u8',
            'push_protocol'    => 'rtmp',
            'push_url'         => 'rtmp://live.example.com/live',
            'push_stream_key'  => 'key123',
            'push_video_codec' => 'copy',
            'push_audio_codec' => 'aac',
            'dvr_duration'     => 3600,
            'segment_duration' => 6,
            'record_duration'  => 0,
            'check_interval'   => 5,
            'max_retries'      => 3,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  start() — primary push
    // ═══════════════════════════════════════════════════════════════════

    /** @test */
    public function it_starts_push_when_playlist_exists(): void
    {
        $playlist = '/tmp/test-dvr/live.m3u8';
        $command  = ['ffmpeg', '-i', $playlist, 'rtmp://live.example.com/live/key123'];

        $this->playout->shouldReceive('outputPlaylist')->once()->andReturn($playlist);
        $this->ffmpeg->shouldReceive('pidFile')->twice()->andReturn('/tmp/pid', '/tmp/pid');
        $this->ffmpeg->shouldReceive('readPid')->once()->andReturn(0);
        $this->ffmpeg->shouldReceive('buildPushCommand')->once()->andReturn($command);
        $this->ffmpeg->shouldReceive('logFile')->once()->andReturn('/tmp/log');
        $this->ffmpeg->shouldReceive('startProcess')->once()->andReturn(9999);
        $this->ffmpeg->shouldReceive('clearPid')->once();
        $this->ffmpeg->shouldReceive('stopProcess')->never();

        // Setup playlist file
        $dvrDir = $this->channel->dvr_directory;
        if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);
        file_put_contents($playlist, '#EXTM3U');

        $result = $this->push->start($this->channel);

        $this->assertTrue($result);
        $this->assertSame('live', $this->channel->fresh()->push_status);
        $this->assertSame(9999, $this->channel->fresh()->push_pid);

        unlink($playlist);
        rmdir($dvrDir);
    }

    /** @test */
    public function it_fails_when_push_url_is_empty(): void
    {
        $this->channel->update(['push_url' => '']);

        $result = $this->push->start($this->channel);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_fails_when_playlist_does_not_exist(): void
    {
        $playlist = '/nonexistent/live.m3u8';

        $this->playout->shouldReceive('outputPlaylist')->once()->andReturn($playlist);

        $result = $this->push->start($this->channel);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_fails_and_sets_error_when_ffmpeg_crashes(): void
    {
        $playlist = '/tmp/test-dvr/live.m3u8';
        $command  = ['ffmpeg', '-i', $playlist, 'rtmp://live.example.com/live/key123'];

        $this->playout->shouldReceive('outputPlaylist')->once()->andReturn($playlist);
        $this->ffmpeg->shouldReceive('pidFile')->twice()->andReturn('/tmp/pid');
        $this->ffmpeg->shouldReceive('readPid')->once()->andReturn(0);
        $this->ffmpeg->shouldReceive('buildPushCommand')->once()->andReturn($command);
        $this->ffmpeg->shouldReceive('logFile')->once()->andReturn('/tmp/log');
        $this->ffmpeg->shouldReceive('startProcess')->once()->andThrow(new \RuntimeException('ffmpeg not found'));
        $this->ffmpeg->shouldReceive('clearPid')->once();

        $dvrDir = $this->channel->dvr_directory;
        if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);
        file_put_contents($playlist, '#EXTM3U');

        $result = $this->push->start($this->channel);

        $this->assertFalse($result);
        $this->assertSame('error', $this->channel->fresh()->push_status);

        unlink($playlist);
        rmdir($dvrDir);
    }

    /** @test */
    public function it_stops_existing_push_before_starting_new_one(): void
    {
        $playlist = '/tmp/test-dvr/live.m3u8';
        $command  = ['ffmpeg', '-i', $playlist, 'rtmp://live.example.com/live/key123'];
        $oldPid   = 8888;

        $this->playout->shouldReceive('outputPlaylist')->once()->andReturn($playlist);
        $this->ffmpeg->shouldReceive('pidFile')->times(4)->andReturn('/tmp/pid');
        $this->ffmpeg->shouldReceive('readPid')->once()->andReturn($oldPid);
        $this->ffmpeg->shouldReceive('stopProcess')->once()->with($oldPid);
        $this->ffmpeg->shouldReceive('clearPid')->twice();
        $this->ffmpeg->shouldReceive('buildPushCommand')->once()->andReturn($command);
        $this->ffmpeg->shouldReceive('logFile')->once()->andReturn('/tmp/log');
        $this->ffmpeg->shouldReceive('startProcess')->once()->andReturn(9999);

        $dvrDir = $this->channel->dvr_directory;
        if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);
        file_put_contents($playlist, '#EXTM3U');

        $result = $this->push->start($this->channel);

        $this->assertTrue($result);

        unlink($playlist);
        rmdir($dvrDir);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  stop()
    // ═══════════════════════════════════════════════════════════════════

    /** @test */
    public function it_stops_push_and_sets_status_to_stopped(): void
    {
        $pidFile = '/tmp/pid';
        $pid     = 9999;

        $this->ffmpeg->shouldReceive('pidFile')->once()->andReturn($pidFile);
        $this->ffmpeg->shouldReceive('readPid')->once()->andReturn($pid);
        $this->ffmpeg->shouldReceive('stopProcess')->once()->with($pid);
        $this->ffmpeg->shouldReceive('clearPid')->once()->with($pidFile);

        $this->push->stop($this->channel);

        $this->assertSame('stopped', $this->channel->fresh()->push_status);
        $this->assertNull($this->channel->fresh()->push_pid);
    }

    /** @test */
    public function it_handles_stop_when_no_push_is_running(): void
    {
        $pidFile = '/tmp/pid';

        $this->ffmpeg->shouldReceive('pidFile')->once()->andReturn($pidFile);
        $this->ffmpeg->shouldReceive('readPid')->once()->andReturn(0);
        $this->ffmpeg->shouldReceive('clearPid')->once()->with($pidFile);

        $this->push->stop($this->channel);

        $this->assertSame('stopped', $this->channel->fresh()->push_status);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  isRunning()
    // ═══════════════════════════════════════════════════════════════════

    /** @test */
    public function it_reports_push_not_running_when_no_pid(): void
    {
        $this->ffmpeg->shouldReceive('pidFile')->once()->andReturn('/tmp/pid');
        $this->ffmpeg->shouldReceive('readPid')->once()->andReturn(0);

        $result = $this->push->isRunning($this->channel);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_delegates_is_running_to_ffmpeg(): void
    {
        $pidFile = '/tmp/pid';
        $pid     = 9999;

        $this->ffmpeg->shouldReceive('pidFile')->once()->andReturn($pidFile);
        $this->ffmpeg->shouldReceive('readPid')->once()->andReturn($pid);
        $this->ffmpeg->shouldReceive('isRunning')->once()->with($pid)->andReturn(true);

        $result = $this->push->isRunning($this->channel);

        $this->assertTrue($result);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  DVR playback
    // ═══════════════════════════════════════════════════════════════════

    /** @test */
    public function it_starts_dvr_playback_when_concat_exists(): void
    {
        $dvrDir = $this->channel->dvr_directory;
        if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);
        file_put_contents("{$dvrDir}/concat.txt", "file 'seg_00001.ts'");

        $this->ffmpeg->shouldReceive('pidFile')->twice()->andReturn('/tmp/pid');
        $this->ffmpeg->shouldReceive('readPid')->once()->andReturn(0);
        $this->ffmpeg->shouldReceive('buildDvrPlaybackCommand')->once()->andReturn(['ffmpeg', '-i', 'concat.txt', 'rtmp://...']);
        $this->ffmpeg->shouldReceive('logFile')->once()->andReturn('/tmp/log');
        $this->ffmpeg->shouldReceive('startProcess')->once()->andReturn(7777);
        $this->ffmpeg->shouldReceive('clearPid')->once();

        $result = $this->push->startDvrPlayback($this->channel);

        $this->assertTrue($result);
        $this->assertSame('dvr_playback', $this->channel->fresh()->push_status);

        unlink("{$dvrDir}/concat.txt");
        rmdir($dvrDir);
    }

    /** @test */
    public function it_fails_dvr_playback_when_concat_missing(): void
    {
        $result = $this->push->startDvrPlayback($this->channel);

        $this->assertFalse($result);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Multi-destination push
    // ═══════════════════════════════════════════════════════════════════

    /** @test */
    public function it_starts_enabled_destinations(): void
    {
        $playlist = '/tmp/test-dvr/live.m3u8';
        $command  = ['ffmpeg', '-i', $playlist, 'rtmp://primary/live/key123'];

        $this->playout->shouldReceive('outputPlaylist')->once()->andReturn($playlist);

        $dest = PushDestination::create([
            'channel_id' => $this->channel->id,
            'name'       => 'Backup',
            'url'        => 'rtmp://backup.example.com/live',
            'stream_key' => 'bk123',
            'protocol'   => 'rtmp',
            'enabled'    => true,
        ]);

        $this->ffmpeg->shouldReceive('pidFile')->times(4)->andReturn('/tmp/pid');
        $this->ffmpeg->shouldReceive('readPid')->twice()->andReturn(0);
        $this->ffmpeg->shouldReceive('buildPushCommand')->twice()->andReturn($command);
        $this->ffmpeg->shouldReceive('logFile')->twice()->andReturn('/tmp/log');
        $this->ffmpeg->shouldReceive('startProcess')->twice()->andReturn(9999, 8888);
        $this->ffmpeg->shouldReceive('clearPid')->twice();

        $dvrDir = $this->channel->dvr_directory;
        if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);
        file_put_contents($playlist, '#EXTM3U');

        $result = $this->push->start($this->channel);

        $this->assertTrue($result);
        $dest->refresh();
        $this->assertSame('live', $dest->status);

        unlink($playlist);
        rmdir($dvrDir);
    }

    /** @test */
    public function it_skips_already_running_destinations(): void
    {
        $playlist = '/tmp/test-dvr/live.m3u8';
        $command  = ['ffmpeg', '-i', $playlist, 'rtmp://primary/live/key123'];

        $this->playout->shouldReceive('outputPlaylist')->once()->andReturn($playlist);

        $dest = PushDestination::create([
            'channel_id' => $this->channel->id,
            'name'       => 'Backup',
            'url'        => 'rtmp://backup.example.com/live',
            'stream_key' => 'bk123',
            'protocol'   => 'rtmp',
            'enabled'    => true,
            'pid'        => 5555,
        ]);

        $this->ffmpeg->shouldReceive('isRunning')->once()->with(5555)->andReturn(true);

        // Primary push mocks
        $this->ffmpeg->shouldReceive('pidFile')->times(2)->andReturn('/tmp/pid');
        $this->ffmpeg->shouldReceive('readPid')->once()->andReturn(0);
        $this->ffmpeg->shouldReceive('buildPushCommand')->once()->andReturn($command);
        $this->ffmpeg->shouldReceive('logFile')->once()->andReturn('/tmp/log');
        $this->ffmpeg->shouldReceive('startProcess')->once()->andReturn(9999);
        $this->ffmpeg->shouldReceive('clearPid')->once();

        $dvrDir = $this->channel->dvr_directory;
        if (!is_dir($dvrDir)) mkdir($dvrDir, 0755, true);
        file_put_contents($playlist, '#EXTM3U');

        $result = $this->push->start($this->channel);

        $this->assertTrue($result);

        unlink($playlist);
        rmdir($dvrDir);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Destination URL building
    // ═══════════════════════════════════════════════════════════════════

    /** @test */
    public function it_builds_rtmp_destination_url_with_credentials(): void
    {
        // Use reflection to test private method
        $dest = new PushDestination([
            'url'        => 'rtmp://example.com/live',
            'stream_key' => 'key123',
            'protocol'   => 'rtmp',
            'username'   => 'user',
            'password'   => 'pass',
        ]);

        $reflection = new \ReflectionClass($this->push);
        $method = $reflection->getMethod('buildDestinationUrl');

        $url = $method->invoke($this->push, $dest);

        $this->assertStringContainsString('user:pass@example.com', $url);
        $this->assertStringContainsString('/key123', $url);
    }

    /** @test */
    public function it_builds_srt_destination_url(): void
    {
        $dest = new PushDestination([
            'url'        => 'srt://example.com:9000',
            'stream_key' => 'stream',
            'protocol'   => 'srt',
            'username'   => 'srtuser',
            'password'   => 'srtpass',
        ]);

        $reflection = new \ReflectionClass($this->push);
        $method = $reflection->getMethod('buildDestinationUrl');

        $url = $method->invoke($this->push, $dest);

        $this->assertStringContainsString('srt://example.com:9000/stream', $url);
        $this->assertStringContainsString('mode=caller', $url);
        $this->assertStringContainsString('username=srtuser', $url);
        $this->assertStringContainsString('passphrase=srtpass', $url);
    }
}
