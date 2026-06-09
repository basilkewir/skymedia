<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('source_type', ['hls', 'udp', 'mpegts', 'rtmp', 'srt']);
            $table->string('source_url');
            $table->enum('push_protocol', ['rtmp', 'srt'])->default('rtmp');
            $table->string('push_url')->nullable();
            $table->string('push_stream_key')->nullable();
            $table->integer('dvr_duration')->default(3600)->comment('DVR window in seconds');
            $table->integer('segment_duration')->default(4)->comment('Segment duration in seconds');
            $table->string('dvr_path')->nullable();
            $table->boolean('is_active')->default(false);
            $table->enum('stream_status', ['idle', 'starting', 'live', 'dvr_playback', 'error', 'stopped'])->default('idle');
            $table->boolean('source_live')->default(false);
            $table->integer('pid')->nullable();
            $table->integer('dvr_pid')->nullable();
            $table->timestamp('last_live_at')->nullable();
            $table->timestamp('last_check_at')->nullable();
            $table->integer('check_interval')->default(5)->comment('Health check interval in seconds');
            $table->integer('max_retries')->default(3);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
