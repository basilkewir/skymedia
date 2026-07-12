#!/usr/bin/env bash
# Create a test channel with IPTV source and push to external Wowza.
# Run inside the app container after docker compose is up.
#
# Usage (from host):
#   docker compose -f deployment/docker-compose.local-test.yml exec app \
#     bash /var/www/skymedia/deployment/setup-test-channel.sh

set -e
cd /var/www/skymedia

echo "=== Setting up test channel ==="

php artisan tinker --execute="
use App\Models\Channel;
use App\Models\PushDestination;

\$channel = Channel::firstOrCreate(
    ['slug' => 'test-iptv'],
    [
        'name'                => 'Test IPTV',
        'source_type'         => 'hls',
        'source_url'          => 'http://cvesr.mor-esp.cc:80/YT4RKDH/PPCUVPY/56799',
        'push_protocol'       => 'rtmp',
        'push_video_codec'    => 'copy',
        'push_audio_codec'    => 'aac',
        'push_video_bitrate'  => 2000,
        'push_audio_bitrate'  => 128,
        'push_audio_samplerate' => 48000,
        'push_audio_channels' => 2,
        'push_framerate'      => 25,
        'segment_duration'    => 4,
        'dvr_duration'        => 7200,
        'dvr_enabled'         => true,
        'record_duration'     => 3600,
        'is_active'           => true,
        'check_interval'      => 3,
        'max_retries'         => 5,
    ]
);

PushDestination::firstOrCreate(
    ['channel_id' => \$channel->id, 'name' => 'Wowza Test'],
    [
        'protocol'    => 'rtmp',
        'url'         => 'rtmp://158.69.0.203:1937/static',
        'stream_key'  => 'testing',
        'enabled'     => true,
    ]
);

echo 'Channel created: ' . \$channel->name . ' (ID: ' . \$channel->id . ')' . PHP_EOL;
"

echo ""
echo "=== Starting channel ==="
php artisan streams:start test-iptv

echo ""
echo "=== Done ==="
echo "Web UI:       http://localhost:8080"
echo "RTMP stats:   http://localhost:8082/stat"
echo "Monitor logs: docker compose -f deployment/docker-compose.local-test.yml logs -f app"
