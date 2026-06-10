<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('filepath');
            $table->unsignedBigInteger('filesize')->default(0)->comment('bytes');
            $table->float('duration')->default(0)->comment('seconds');
            $table->string('status', 20)->default('recording')
                  ->comment('recording|completed|failed');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['channel_id', 'status']);
            $table->index(['channel_id', 'completed_at']);
        });

        Schema::table('channels', function (Blueprint $table) {
            // How long each recording should be (seconds). 0 = disabled.
            $table->unsignedInteger('record_duration')->default(0)
                  ->comment('seconds per recording, 0 = disabled')->after('dvr_duration');
            // PID of the running ffmpeg record process
            $table->unsignedInteger('record_pid')->nullable()->after('dvr_pid');
            // recording | idle | error
            $table->string('record_status', 20)->default('idle')->after('dvr_status');
            // Absolute path to the last successfully completed recording file
            $table->string('fallback_recording_path', 1000)->nullable()
                  ->after('dvr_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recordings');
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['record_duration', 'record_pid', 'record_status', 'fallback_recording_path']);
        });
    }
};
