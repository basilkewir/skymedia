<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Channel;
use App\Services\DVRService;
use App\Services\FFmpegService;
use Tests\TestCase;

class FFmpegServiceTest extends TestCase
{
    private FFmpegService $ffmpeg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ffmpeg = app(FFmpegService::class);
    }

    /** @test */
    public function it_builds_hls_ingest_command(): void
    {
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');

        $cmd = $this->ffmpeg->buildIngestCommand($channel);

        $this->assertContains('ffmpeg', $cmd[0]);
        $this->assertContains('-f', $cmd);
        $this->assertContains('hls', $cmd);
        $this->assertContains('live.m3u8', end($cmd));
    }

    /** @test */
    public function it_builds_udp_ingest_command(): void
    {
        $channel = $this->makeChannel('udp', 'udp://239.0.0.1:1234');

        $cmd = $this->ffmpeg->buildIngestCommand($channel);

        $this->assertContains('ffmpeg', $cmd[0]);
        $this->assertContains('udp://239.0.0.1:1234', $cmd);
    }

    /** @test */
    public function it_builds_srt_ingest_command(): void
    {
        $channel = $this->makeChannel('srt', 'srt://source.example.com:9000');

        $cmd = $this->ffmpeg->buildIngestCommand($channel);

        $this->assertContains('ffmpeg', $cmd[0]);
        $cmdStr = implode(' ', $cmd);
        $this->assertStringContainsString('srt://source.example.com:9000', $cmdStr);
    }

    /** @test */
    public function it_builds_push_command_with_rtmp_url(): void
    {
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');
        $channel->push_protocol = 'rtmp';
        $channel->push_url      = 'rtmp://live.example.com/live';
        $channel->push_stream_key = 'key123';

        $cmd = $this->ffmpeg->buildPushCommand($channel, '/tmp/test.m3u8');
        $cmdStr = implode(' ', $cmd);

        $this->assertStringContainsString('rtmp://live.example.com/live/key123', $cmdStr);
    }

    /** @test */
    public function it_builds_push_command_with_srt_url(): void
    {
        $channel = $this->makeChannel('srt', 'srt://source.example.com:9000');
        $channel->push_protocol = 'srt';
        $channel->push_url      = 'srt://dest.example.com:9000';
        $channel->push_stream_key = 'stream';

        $cmd = $this->ffmpeg->buildPushCommand($channel, '/tmp/test.m3u8');
        $cmdStr = implode(' ', $cmd);

        $this->assertStringContainsString('srt://dest.example.com:9000/stream', $cmdStr);
    }

    /** @test */
    public function it_builds_push_command_with_credentials(): void
    {
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');
        $channel->push_protocol = 'rtmp';
        $channel->push_url      = 'rtmp://live.example.com/live';
        $channel->push_stream_key = 'key123';
        $channel->push_username  = 'user';
        $channel->push_password  = 'pass';

        $cmd = $this->ffmpeg->buildPushCommand($channel, '/tmp/test.m3u8');
        $cmdStr = implode(' ', $cmd);

        $this->assertStringContainsString('user:pass@live.example.com', $cmdStr);
    }

    /** @test */
    public function it_includes_video_encoding_flags_when_not_copy(): void
    {
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');
        $channel->push_video_codec   = 'h264';
        $channel->push_video_bitrate = 4000;
        $channel->push_framerate     = 30;

        $cmd = $this->ffmpeg->buildPushCommand($channel, '/tmp/test.m3u8');
        $cmdStr = implode(' ', $cmd);

        $this->assertStringContainsString('libx264', $cmdStr);
        $this->assertStringContainsString('4000k', $cmdStr);
    }

    /** @test */
    public function it_includes_audio_encoding_flags(): void
    {
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');
        $channel->push_audio_codec      = 'mp3';
        $channel->push_audio_bitrate    = 192;
        $channel->push_audio_samplerate = 44100;
        $channel->push_audio_channels   = 2;

        $cmd = $this->ffmpeg->buildPushCommand($channel, '/tmp/test.m3u8');
        $cmdStr = implode(' ', $cmd);

        $this->assertStringContainsString('libmp3lame', $cmdStr);
        $this->assertStringContainsString('192k', $cmdStr);
    }

    /** @test */
    public function pid_file_has_correct_path(): void
    {
        $channel = Channel::factory()->make(['id' => 42]);

        $path = $this->ffmpeg->pidFile($channel, 'ingest');
        $this->assertStringContainsString('ingest_42.pid', $path);
    }

    /** @test */
    public function log_file_has_correct_path(): void
    {
        $channel = Channel::factory()->make(['id' => 42]);

        $path = $this->ffmpeg->logFile($channel, 'push');
        $this->assertStringContainsString('push_42.log', $path);
    }

    /** @test */
    public function read_pid_returns_zero_for_missing_file(): void
    {
        $pid = $this->ffmpeg->readPid('/tmp/nonexistent_pid_file_12345.pid');
        $this->assertSame(0, $pid);
    }

    /** @test */
    public function is_running_returns_false_for_invalid_pid(): void
    {
        $this->assertFalse($this->ffmpeg->isRunning(0));
        $this->assertFalse($this->ffmpeg->isRunning(-1));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeChannel(string $sourceType, string $sourceUrl): Channel
    {
        $channel = new Channel([
            'name'             => 'Test',
            'slug'             => 'test',
            'source_type'      => $sourceType,
            'source_url'       => $sourceUrl,
            'push_protocol'    => 'rtmp',
            'push_url'         => 'rtmp://localhost/live',
            'push_stream_key'  => 'k',
            'push_video_codec' => 'copy',
            'push_audio_codec' => 'aac',
            'dvr_duration'     => 3600,
            'segment_duration' => 6,
            'record_duration'  => 0,
            'check_interval'   => 5,
            'max_retries'      => 3,
            'dvr_path'         => '/tmp/test-dvr',
        ]);
        $channel->id = 99;
        return $channel;
    }
}
