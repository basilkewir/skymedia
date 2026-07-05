<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channels')) {
            return;
        }

        Schema::create('channels', function (Blueprint $table) {
            $table->id();

            // ── Identity ──────────────────────────────────────────────────
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('notes')->nullable();

            // ── Source ────────────────────────────────────────────────────
            $table->enum('source_type', ['hls', 'udp', 'mpegts', 'rtmp', 'srt']);
            $table->string('source_url');

            // ── Push destination ──────────────────────────────────────────
            $table->enum('push_protocol', ['rtmp', 'srt'])->default('rtmp');
            $table->string('push_url')->nullable();
            $table->string('push_stream_key')->nullable();

            // ── Push video encoding ───────────────────────────────────────
            // codec: copy | h264 | h265 | vp8 | vp9
            $table->string('push_video_codec')->default('copy');
            $table->unsignedInteger('push_video_bitrate')->nullable()->comment('kbps');
            $table->string('push_resolution')->nullable()->comment('e.g. 1920x1080 or 1280:-2');
            $table->unsignedTinyInteger('push_framerate')->nullable()->comment('fps');

            // ── Push audio encoding ───────────────────────────────────────
            // codec: copy | aac | mp3 | opus | ac3
            $table->string('push_audio_codec')->default('aac');
            $table->unsignedSmallInteger('push_audio_bitrate')->default(128)->comment('kbps');
            $table->unsignedInteger('push_audio_samplerate')->default(44100)->comment('Hz');
            $table->unsignedTinyInteger('push_audio_channels')->default(2);

            // ── DVR ───────────────────────────────────────────────────────
            $table->unsignedInteger('dvr_duration')->default(3600)->comment('Rolling window in seconds');
            $table->unsignedSmallInteger('segment_duration')->default(2)->comment('Segment duration in seconds');
            $table->string('dvr_path')->nullable();

            // ── Recording (periodic full recordings for fallback) ─────────
            // record_duration: seconds per recording file (0 = disabled)
            // e.g. 3600 = record 1h files; when source goes offline the latest
            // completed file is looped to the push output
            $table->unsignedInteger('record_duration')->default(3600)->comment('Recording file length in seconds. 0 = disabled');
            $table->string('fallback_recording_path')->nullable()->comment('Latest completed recording file');

            // ── Runtime state ─────────────────────────────────────────────
            $table->boolean('is_active')->default(false);
            $table->enum('stream_status', ['idle','starting','live','offline','fallback','error','stopped'])->default('idle');
            $table->enum('push_status',   ['idle','starting','live','fallback','error','stopped'])->default('idle');
            $table->enum('dvr_status',    ['idle','starting','recording','error'])->default('idle');
            $table->enum('record_status', ['idle','recording','finishing','error'])->default('idle');
            $table->boolean('source_live')->default(false);

            // ── Process IDs ───────────────────────────────────────────────
            $table->unsignedBigInteger('pid')->nullable()->comment('Ingest ffmpeg PID');
            $table->unsignedBigInteger('push_pid')->nullable()->comment('Push ffmpeg PID');
            $table->unsignedBigInteger('record_pid')->nullable()->comment('Recording ffmpeg PID');

            // ── Retry tracking ────────────────────────────────────────────
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();

            // ── Behaviour ─────────────────────────────────────────────────
            $table->unsignedSmallInteger('check_interval')->default(5)->comment('Health check interval in seconds');
            $table->unsignedTinyInteger('max_retries')->default(3);

            // ── Timestamps ───────────────────────────────────────────────
            $table->timestamp('last_live_at')->nullable();
            $table->timestamp('last_check_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'stream_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
