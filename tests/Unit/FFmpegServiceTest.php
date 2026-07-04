<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Channel;
use App\Services\FFmpegService;
use Illuminate\Support\Facades\Http;
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

        $this->assertStringContainsString('ffmpeg', $cmd[0]);
        $this->assertContains('-f', $cmd);
        $this->assertContains('hls', $cmd);
        $this->assertStringContainsString('live.m3u8', end($cmd));
    }

    /** @test */
    public function it_builds_udp_ingest_command(): void
    {
        $channel = $this->makeChannel('udp', 'udp://239.0.0.1:1234');

        $cmd = $this->ffmpeg->buildIngestCommand($channel);

        $this->assertStringContainsString('ffmpeg', $cmd[0]);
        $this->assertContains('udp://239.0.0.1:1234', $cmd);
    }

    /** @test */
    public function it_builds_srt_ingest_command(): void
    {
        $channel = $this->makeChannel('srt', 'srt://source.example.com:9000');

        $cmd = $this->ffmpeg->buildIngestCommand($channel);

        $this->assertStringContainsString('ffmpeg', $cmd[0]);
        $cmdStr = implode(' ', $cmd);
        $this->assertStringContainsString('srt://source.example.com:9000', $cmdStr);
    }

    /** @test */
    public function it_builds_an_rtmp_push_listener_ingest_command(): void
    {
        $channel = $this->makeChannel('rtmp', 'push://listener');
        $channel->ingest_mode = 'push';
        $channel->ingest_port = 20001;
        $channel->rtmp_input_key = 'secret-key';

        $cmd = implode(' ', $this->ffmpeg->buildIngestCommand($channel));

        $this->assertStringContainsString('-listen 1', $cmd);
        $this->assertStringContainsString('rtmp://0.0.0.0:20001/live/secret-key', $cmd);
    }

    /** @test */
    public function it_builds_an_srt_push_listener_ingest_command(): void
    {
        $channel = $this->makeChannel('srt', 'push://listener');
        $channel->ingest_mode = 'push';
        $channel->ingest_port = 30001;

        $cmd = implode(' ', $this->ffmpeg->buildIngestCommand($channel));

        $this->assertStringContainsString('srt://0.0.0.0:30001?mode=listener', $cmd);
        $this->assertStringNotContainsString('-listen 1', $cmd);
    }

    /** @test */
    public function it_uses_only_a_small_live_buffer_when_dvr_is_disabled(): void
    {
        $channel = $this->makeChannel('rtmp', 'push://listener');
        $channel->ingest_mode = 'push';
        $channel->ingest_port = 20001;
        $channel->rtmp_input_key = 'secret-key';
        $channel->dvr_enabled = false;

        $cmd = implode(' ', $this->ffmpeg->buildIngestCommand($channel));

        $this->assertStringContainsString('-hls_list_size 5', $cmd);
        $this->assertStringContainsString('-hls_delete_threshold 2', $cmd);
    }

    /** @test */
    public function it_builds_push_command_with_rtmp_url(): void
    {
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');
        $channel->push_protocol = 'rtmp';
        $channel->push_url = 'rtmp://live.example.com/live';
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
        $channel->push_url = 'srt://dest.example.com:9000';
        $channel->push_stream_key = 'stream';

        $cmd = $this->ffmpeg->buildPushCommand($channel, '/tmp/test.m3u8');
        $cmdStr = implode(' ', $cmd);

        $this->assertStringContainsString('srt://dest.example.com:9000/stream', $cmdStr);
    }

    /** @test */
    public function it_builds_push_command_with_hls_output(): void
    {
        $dvrDir = sys_get_temp_dir() . '/skymedia_hls_push_test_' . uniqid();
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');
        $channel->push_protocol = 'hls';
        $channel->push_url = $dvrDir;
        $channel->push_stream_key = 'channel1';
        $channel->push_hls_segment_duration = 4;
        $channel->push_hls_list_size = 12;

        $cmd = $this->ffmpeg->buildPushCommand($channel, '/tmp/test.m3u8');
        $cmdStr = implode(' ', $cmd);

        $this->assertContains('-f', $cmd);
        $this->assertContains('hls', $cmd);
        $this->assertStringContainsString('-hls_time 4', $cmdStr);
        $this->assertStringContainsString('-hls_list_size 12', $cmdStr);
        $this->assertStringContainsString("{$dvrDir}/channel1/index.m3u8", $cmdStr);

        // Cleanup created directory
        @rmdir("{$dvrDir}/channel1");
        @rmdir($dvrDir);
    }

    /** @test */
    public function it_builds_hls_push_command_with_http_target(): void
    {
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');
        $channel->push_protocol = 'hls';
        $channel->push_url = 'https://cdn.example.com/hls';
        $channel->push_stream_key = 'channel1';

        $cmd = $this->ffmpeg->buildPushCommand($channel, '/tmp/test.m3u8');
        $cmdStr = implode(' ', $cmd);

        $this->assertStringContainsString('-method PUT', $cmdStr);
        $this->assertStringContainsString('https://cdn.example.com/hls/channel1/index.m3u8', $cmdStr);
    }

    /** @test */
    public function it_builds_push_command_with_credentials(): void
    {
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');
        $channel->push_protocol = 'rtmp';
        $channel->push_url = 'rtmp://live.example.com/live';
        $channel->push_stream_key = 'key123';
        $channel->push_username = 'user';
        $channel->push_password = 'pass';

        $cmd = $this->ffmpeg->buildPushCommand($channel, '/tmp/test.m3u8');
        $cmdStr = implode(' ', $cmd);

        $this->assertStringContainsString('user:pass@live.example.com', $cmdStr);
    }

    /** @test */
    public function it_includes_video_encoding_flags_when_not_copy(): void
    {
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');
        $channel->push_video_codec = 'h264';
        $channel->push_video_bitrate = 4000;
        $channel->push_framerate = 30;

        $cmd = $this->ffmpeg->buildPushCommand($channel, '/tmp/test.m3u8');
        $cmdStr = implode(' ', $cmd);

        $this->assertStringContainsString('libx264', $cmdStr);
        $this->assertStringContainsString('4000k', $cmdStr);
    }

    /** @test */
    public function it_includes_audio_encoding_flags(): void
    {
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');
        $channel->push_audio_codec = 'mp3';
        $channel->push_audio_bitrate = 192;
        $channel->push_audio_samplerate = 44100;
        $channel->push_audio_channels = 2;

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

    /** @test */
    public function it_adds_browser_user_agent_for_http_hls_sources(): void
    {
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');

        $cmd = $this->ffmpeg->buildIngestCommand($channel);

        $idx = array_search('-user_agent', $cmd, true);
        $this->assertNotFalse($idx, 'HLS ingest should set -user_agent');
        $this->assertStringContainsString('Mozilla/5.0', $cmd[$idx + 1]);
    }

    /** @test */
    public function it_adds_browser_user_agent_for_http_mpegts_sources(): void
    {
        $channel = $this->makeChannel('mpegts', 'http://example.com/channel');

        $cmd = $this->ffmpeg->buildIngestCommand($channel);

        $idx = array_search('-user_agent', $cmd, true);
        $this->assertNotFalse($idx, 'HTTP MPEG-TS ingest should set -user_agent');
        $this->assertStringContainsString('Mozilla/5.0', $cmd[$idx + 1]);
    }

    /** @test */
    public function it_adds_user_agent_to_ffprobe_for_http_sources(): void
    {
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');

        $cmd = $this->ffmpeg->buildProbeCommand($channel);

        $idx = array_search('-user_agent', $cmd, true);
        $this->assertNotFalse($idx, 'HTTP probe should set -user_agent');
        $this->assertStringContainsString('Mozilla/5.0', $cmd[$idx + 1]);
    }

    /** @test */
    public function it_omits_user_agent_for_non_http_sources(): void
    {
        $channel = $this->makeChannel('udp', 'udp://239.0.0.1:1234');

        $cmd = $this->ffmpeg->buildIngestCommand($channel);

        $this->assertNotContains('-user_agent', $cmd);
    }

    /** @test */
    public function it_resolves_hls_master_playlist_to_variant(): void
    {
        Http::fake([
            'https://example.com/master.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000000\nvariant/high.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=500000\nvariant/low.m3u8\n",
                200
            ),
        ]);

        $resolved = $this->ffmpeg->resolveHlsUrl('https://example.com/master.m3u8');

        $this->assertSame('https://example.com/variant/high.m3u8', $resolved);
    }

    /** @test */
    public function it_passthrough_non_master_hls_playlist(): void
    {
        Http::fake([
            'https://example.com/media.m3u8' => Http::response(
                "#EXTM3U\n#EXTINF:4,\nseg.ts\n",
                200
            ),
        ]);

        $resolved = $this->ffmpeg->resolveHlsUrl('https://example.com/media.m3u8');

        $this->assertSame('https://example.com/media.m3u8', $resolved);
    }

    /** @test */
    public function it_uses_resolved_variant_url_for_hls_ingest(): void
    {
        Http::fake([
            'https://example.com/master.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000000\nvariant/high.m3u8\n",
                200
            ),
        ]);

        $channel = $this->makeChannel('hls', 'https://example.com/master.m3u8');
        $cmd = $this->ffmpeg->buildIngestCommand($channel);

        $this->assertContains('https://example.com/variant/high.m3u8', $cmd);
        $this->assertNotContains('https://example.com/master.m3u8', $cmd);
    }

    /** @test */
    public function it_disables_tls_verification_for_hls_by_default(): void
    {
        $channel = $this->makeChannel('hls', 'https://example.com/stream.m3u8');

        $cmd = $this->ffmpeg->buildIngestCommand($channel);

        $idx = array_search('-tls_verify', $cmd, true);
        $this->assertNotFalse($idx, 'HLS ingest should set -tls_verify');
        $this->assertSame('0', $cmd[$idx + 1]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeChannel(string $sourceType, string $sourceUrl): Channel
    {
        $channel = new Channel([
            'name' => 'Test',
            'slug' => 'test',
            'source_type' => $sourceType,
            'source_url' => $sourceUrl,
            'push_protocol' => 'rtmp',
            'push_url' => 'rtmp://localhost/live',
            'push_stream_key' => 'k',
            'push_video_codec' => 'copy',
            'push_audio_codec' => 'aac',
            'dvr_duration' => 3600,
            'segment_duration' => 6,
            'record_duration' => 0,
            'check_interval' => 5,
            'max_retries' => 3,
            'dvr_path' => '/tmp/test-dvr',
        ]);
        $channel->id = 99;

        return $channel;
    }

    /** @test */
    public function it_builds_hls_ingest_command_for_local_file(): void
    {
        $channel = $this->makeChannel('hls', 'file:///tmp/test-hls/live.m3u8');

        $cmd = $this->ffmpeg->buildIngestCommand($channel);
        $cmdStr = implode(' ', $cmd);

        // Network-only options must not be present for file:// inputs
        $this->assertStringNotContainsString('-timeout', $cmdStr);
        $this->assertStringNotContainsString('-reconnect', $cmdStr);
        $this->assertStringContainsString('file:///tmp/test-hls/live.m3u8', $cmdStr);
    }
}
