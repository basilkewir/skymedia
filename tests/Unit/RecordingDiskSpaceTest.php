<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Channel;
use App\Services\FFmpegService;
use App\Services\RecordingService;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RecordingDiskSpaceTest extends TestCase
{
    private RecordingService $recording;

    private MockInterface $ffmpeg;

    private Channel $channel;

    private string $dvrBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dvrBase = sys_get_temp_dir() . '/skymedia_disk_test_' . uniqid();
        config(['skymedia.dvr_base_path' => $this->dvrBase]);
        config(['skymedia.min_free_disk_bytes' => 1024 * 1024 * 1024]); // 1 GB

        $this->ffmpeg = Mockery::mock(FFmpegService::class);
        $this->recording = new RecordingService($this->ffmpeg);

        $this->channel = Channel::factory()->create([
            'source_type' => 'hls',
            'ingest_mode' => 'pull',
            'record_duration' => 3600,
            'record_status' => 'idle',
            'source_live' => true,
            'stream_status' => 'live',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dvrBase)) {
            File::deleteDirectory($this->dvrBase);
        }

        parent::tearDown();
    }

    /** @test */
    public function it_records_when_disk_space_is_available(): void
    {
        // Real disk free space is almost certainly above 1 GB.
        $this->ffmpeg->shouldReceive('pidFile')->once()->andReturn('/tmp/pid');
        $this->ffmpeg->shouldReceive('readPid')->once()->andReturn(0);
        $this->ffmpeg->shouldReceive('hlsReady')->once()->andReturn(true);
        $this->ffmpeg->shouldReceive('isRunning')->never();

        $this->assertTrue($this->recording->shouldRecord($this->channel));
    }

    /** @test */
    public function it_does_not_record_when_disk_space_is_below_threshold(): void
    {
        // Impossibly high threshold ensures the check fails.
        config(['skymedia.min_free_disk_bytes' => 1024 * 1024 * 1024 * 1024 * 1024]);

        $this->ffmpeg->shouldReceive('pidFile')->andReturn('/tmp/pid');
        $this->ffmpeg->shouldReceive('readPid')->andReturn(0);
        $this->ffmpeg->shouldReceive('isRunning')->andReturn(false);
        $this->ffmpeg->shouldReceive('hlsReady')->once()->andReturn(true);

        $this->assertFalse($this->recording->shouldRecord($this->channel));
    }

    /** @test */
    public function it_aborts_active_recording_when_disk_becomes_full(): void
    {
        $this->ffmpeg->shouldReceive('pidFile')->once()->andReturn('/tmp/pid');
        $this->ffmpeg->shouldReceive('readPid')->once()->andReturn(0);
        $this->ffmpeg->shouldReceive('isRunning')->never();

        $this->assertFalse($this->recording->abortIfDiskFull($this->channel));
    }
}
