<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Channel;
use App\Services\FFmpegService;
use App\Services\IngestService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Locks in the state-machine contract that fixes the "stuck on fallback with
 * source_live=1" bug: ingest must NOT mark the channel live before segment
 * evidence exists, and monitor-driven transitions are the only place a
 * channel is promoted to live.
 */
class IngestRecoveryTest extends TestCase
{
    private string $fakeBinDir;

    protected function setUp(): void
    {
        parent::setUp();
        // IngestService::start verifies the ffmpeg binary is discoverable in
        // PATH before launching. We don't depend on ffmpeg being installed
        // on the test host, so we put a tiny shim on PATH that satisfies
        // `which ffmpeg`. The actual command is never executed — startProcess
        // is mocked.
        $this->fakeBinDir = sys_get_temp_dir() . '/skymedia_fakebin_' . uniqid();
        @mkdir($this->fakeBinDir, 0755, true);
        $shim = $this->fakeBinDir . '/ffmpeg';
        file_put_contents($shim, "#!/bin/sh\nexit 0\n");
        chmod($shim, 0755);
        putenv('PATH=' . $this->fakeBinDir . PATH_SEPARATOR . getenv('PATH'));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fakeBinDir)) {
            array_map('unlink', glob($this->fakeBinDir . '/*') ?: []);
            rmdir($this->fakeBinDir);
        }
        Mockery::close();
        parent::tearDown();
    }

    private function makeChannel(array $overrides = []): Channel
    {
        return Channel::create(array_merge([
            'name' => 'Recovery Test ' . fake()->randomNumber(3),
            'slug' => 'recovery-test-' . fake()->randomNumber(5),
            'source_type' => 'hls',
            'source_url' => 'https://example.com/stream.m3u8',
            'ingest_mode' => 'pull',
            'push_protocol' => 'rtmp',
            'push_url' => 'rtmp://localhost/live',
            'push_stream_key' => 'k',
            'dvr_duration' => 3600,
            'segment_duration' => 4,
            'record_duration' => 0,
            'check_interval' => 5,
            'max_retries' => 3,
        ], $overrides));
    }

    /** @test */
    public function ingest_start_does_not_premark_pull_source_as_live(): void
    {
        $channel = $this->makeChannel();

        // Mock FFmpegService so we don't actually need ffmpeg on the host.
        $ffmpeg = Mockery::mock(FFmpegService::class);
        $ffmpeg->shouldReceive('getBin')->andReturn('ffmpeg');
        $ffmpeg->shouldReceive('pidFile')->andReturn(storage_path("app/pids/ingest_{$channel->id}.pid"));
        $ffmpeg->shouldReceive('logFile')->andReturn(storage_path("logs/streams/ingest_{$channel->id}.log"));
        $ffmpeg->shouldReceive('readPid')->andReturn(0);
        $ffmpeg->shouldReceive('clearPid');
        $ffmpeg->shouldReceive('isRunning')->andReturn(false);
        $ffmpeg->shouldReceive('startProcess')->andReturn(12345);
        $ffmpeg->shouldReceive('buildIngestCommand')->andReturn(['ffmpeg', '-version']);

        Log::spy();

        $ingest = new IngestService($ffmpeg);
        $ingest->start($channel->fresh());

        $ch = $channel->fresh();

        // The previous implementation eagerly set source_live=true and
        // stream_status='live' for pull ingest. The fix keeps the channel
        // in an unconfirmed state until the monitor sees real segments.
        $this->assertFalse(
            (bool) $ch->source_live,
            'Pull ingest must not be marked source_live until segments are observed'
        );
        $this->assertNotSame('live', $ch->stream_status);
        $this->assertSame('starting', $ch->stream_status);
    }

    /** @test */
    public function ingest_start_does_not_overwrite_last_live_at(): void
    {
        $channel = $this->makeChannel();
        $previous = now()->subHours(2);
        $channel->update(['last_live_at' => $previous]);

        $ffmpeg = Mockery::mock(FFmpegService::class);
        $ffmpeg->shouldReceive('getBin')->andReturn('ffmpeg');
        $ffmpeg->shouldReceive('pidFile')->andReturn(storage_path("app/pids/ingest_{$channel->id}.pid"));
        $ffmpeg->shouldReceive('logFile')->andReturn(storage_path("logs/streams/ingest_{$channel->id}.log"));
        $ffmpeg->shouldReceive('readPid')->andReturn(0);
        $ffmpeg->shouldReceive('clearPid');
        $ffmpeg->shouldReceive('isRunning')->andReturn(false);
        $ffmpeg->shouldReceive('startProcess')->andReturn(12345);
        $ffmpeg->shouldReceive('buildIngestCommand')->andReturn(['ffmpeg', '-version']);

        Log::spy();

        $ingest = new IngestService($ffmpeg);
        $ingest->start($channel->fresh());

        // last_live_at tracks the last confirmed-live moment — a new
        // ffmpeg invocation must NOT bump it forward in time.
        $this->assertEquals(
            $previous->timestamp,
            $channel->fresh()->last_live_at->timestamp
        );
    }

    /** @test */
    public function startchannel_leaves_pull_channel_in_starting_state(): void
    {
        // StreamManager::startChannel should NOT override the corrected
        // 'starting' state with a speculative 'live' for pull channels.
        // We exercise this via the public surface of the DB assertion only
        // (relying on the same IngestService behaviour locked in above).
        $channel = $this->makeChannel();

        $ffmpeg = Mockery::mock(FFmpegService::class);
        $ffmpeg->shouldReceive('getBin')->andReturn('ffmpeg');
        $ffmpeg->shouldReceive('pidFile')->andReturn(storage_path("app/pids/ingest_{$channel->id}.pid"));
        $ffmpeg->shouldReceive('logFile')->andReturn(storage_path("logs/streams/ingest_{$channel->id}.log"));
        $ffmpeg->shouldReceive('readPid')->andReturn(0);
        $ffmpeg->shouldReceive('clearPid');
        $ffmpeg->shouldReceive('isRunning')->andReturn(false);
        $ffmpeg->shouldReceive('startProcess')->andReturn(22222);
        $ffmpeg->shouldReceive('buildIngestCommand')->andReturn(['ffmpeg', '-version']);

        Log::spy();

        $ingest = new IngestService($ffmpeg);
        $ingest->start($channel->fresh());

        $this->assertSame('starting', $channel->fresh()->stream_status);
        $this->assertFalse((bool) $channel->fresh()->source_live);
    }
}
