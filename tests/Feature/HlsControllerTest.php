<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Channel;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HlsControllerTest extends TestCase
{
    /** @test */
    public function it_serves_hls_playlist(): void
    {
        $channel = Channel::factory()->create();
        $dvrDir = $channel->dvr_directory;
        File::makeDirectory($dvrDir, 0755, true, true);
        File::put("{$dvrDir}/output.m3u8", "#EXTM3U\n#EXT-X-VERSION:3\n");

        $response = $this->get("/hls/{$channel->id}/output.m3u8");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');
        $response->assertHeader('Access-Control-Allow-Origin', '*');

        File::deleteDirectory($dvrDir);
    }

    /** @test */
    public function it_serves_transport_stream_segments(): void
    {
        $channel = Channel::factory()->create();
        $dvrDir = $channel->dvr_directory;
        File::makeDirectory($dvrDir, 0755, true, true);
        File::put("{$dvrDir}/seg_00001.ts", "\x00\x00\x00\x00");

        $response = $this->get("/hls/{$channel->id}/seg_00001.ts");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'video/mp2t');

        File::deleteDirectory($dvrDir);
    }

    /** @test */
    public function it_rejects_files_outside_dvr_directory(): void
    {
        $channel = Channel::factory()->create();

        $response = $this->get("/hls/{$channel->id}/../.env");

        $response->assertStatus(404);
    }
}
