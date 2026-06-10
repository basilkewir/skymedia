<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Video encoding
            $table->string('push_video_codec', 20)->default('copy')->after('push_stream_key');
            $table->unsignedInteger('push_video_bitrate')->nullable()->after('push_video_codec')->comment('kbps, null = source');
            $table->string('push_resolution', 20)->nullable()->after('push_video_bitrate')->comment('WxH e.g. 1920x1080, null = source');
            $table->unsignedSmallInteger('push_framerate')->nullable()->after('push_resolution')->comment('fps, null = source');

            // Audio encoding
            $table->string('push_audio_codec', 20)->default('aac')->after('push_framerate');
            $table->unsignedSmallInteger('push_audio_bitrate')->default(128)->after('push_audio_codec')->comment('kbps');
            $table->unsignedInteger('push_audio_samplerate')->default(44100)->after('push_audio_bitrate');
            $table->unsignedTinyInteger('push_audio_channels')->default(2)->after('push_audio_samplerate');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn([
                'push_video_codec', 'push_video_bitrate', 'push_resolution', 'push_framerate',
                'push_audio_codec', 'push_audio_bitrate', 'push_audio_samplerate', 'push_audio_channels',
            ]);
        });
    }
};
