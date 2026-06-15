<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Channels — frequently filtered columns
        Schema::table('channels', function (Blueprint $table) {
            if (!Schema::hasIndex('channels', 'channels_is_active_status_index')) {
                $table->index(['is_active', 'stream_status'], 'channels_is_active_status_index');
            }
        });

        // DVR segments — heavy read queries
        Schema::table('dvr_segments', function (Blueprint $table) {
            if (!Schema::hasIndex('dvr_segments', 'dvr_segments_channel_seq_index')) {
                $table->index(['channel_id', 'sequence'], 'dvr_segments_channel_seq_index');
            }
            if (!Schema::hasIndex('dvr_segments', 'dvr_segments_channel_time_index')) {
                $table->index(['channel_id', 'recorded_at'], 'dvr_segments_channel_time_index');
            }
        });

        // Stream logs — filtered by channel and time
        Schema::table('stream_logs', function (Blueprint $table) {
            if (!Schema::hasIndex('stream_logs', 'stream_logs_channel_created_index')) {
                $table->index(['channel_id', 'created_at'], 'stream_logs_channel_created_index');
            }
            if (!Schema::hasIndex('stream_logs', 'stream_logs_created_index')) {
                $table->index('created_at', 'stream_logs_created_index');
            }
            if (!Schema::hasIndex('stream_logs', 'stream_logs_level_index')) {
                $table->index('level', 'stream_logs_level_index');
            }
        });

        // Recordings — filtered by channel and status
        Schema::table('recordings', function (Blueprint $table) {
            if (!Schema::hasIndex('recordings', 'recordings_channel_status_index')) {
                $table->index(['channel_id', 'status'], 'recordings_channel_status_index');
            }
            if (!Schema::hasIndex('recordings', 'recordings_completed_index')) {
                $table->index(['channel_id', 'completed_at'], 'recordings_completed_index');
            }
        });

        // Per-channel recording retention
        Schema::table('channels', function (Blueprint $table) {
            if (!Schema::hasColumn('channels', 'keep_recordings')) {
                $table->unsignedTinyInteger('keep_recordings')->default(3)
                    ->after('record_duration')
                    ->comment('Number of completed recordings to retain per channel');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('keep_recordings');
        });
    }
};
