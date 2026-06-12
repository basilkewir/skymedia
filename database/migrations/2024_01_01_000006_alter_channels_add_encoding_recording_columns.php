<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds new columns to the channels table for existing installations.
 * Every column addition is guarded with hasColumn() so this migration
 * is safe to run multiple times and never fails on a fresh install
 * (where the create migration already added all columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('channels')) {
            return; // fresh install — create migration handles it
        }

        Schema::table('channels', function (Blueprint $table) {

            // Push video encoding
            if (!Schema::hasColumn('channels', 'push_video_codec')) {
                $table->string('push_video_codec')->default('copy')->after('push_stream_key');
            }
            if (!Schema::hasColumn('channels', 'push_video_bitrate')) {
                $table->unsignedInteger('push_video_bitrate')->nullable()->comment('kbps')->after('push_video_codec');
            }
            if (!Schema::hasColumn('channels', 'push_resolution')) {
                $table->string('push_resolution')->nullable()->after('push_video_bitrate');
            }
            if (!Schema::hasColumn('channels', 'push_framerate')) {
                $table->unsignedTinyInteger('push_framerate')->nullable()->comment('fps')->after('push_resolution');
            }

            // Push audio encoding
            if (!Schema::hasColumn('channels', 'push_audio_codec')) {
                $table->string('push_audio_codec')->default('aac')->after('push_framerate');
            }
            if (!Schema::hasColumn('channels', 'push_audio_bitrate')) {
                $table->unsignedSmallInteger('push_audio_bitrate')->default(128)->comment('kbps')->after('push_audio_codec');
            }
            if (!Schema::hasColumn('channels', 'push_audio_samplerate')) {
                $table->unsignedInteger('push_audio_samplerate')->default(48000)->comment('Hz')->after('push_audio_bitrate');
            }
            if (!Schema::hasColumn('channels', 'push_audio_channels')) {
                $table->unsignedTinyInteger('push_audio_channels')->default(2)->after('push_audio_samplerate');
            }

            // Recording / fallback
            if (!Schema::hasColumn('channels', 'record_duration')) {
                $table->unsignedInteger('record_duration')->default(3600)
                      ->comment('Recording file length in seconds. 0=disabled')->after('dvr_path');
            }
            if (!Schema::hasColumn('channels', 'fallback_recording_path')) {
                $table->string('fallback_recording_path')->nullable()
                      ->comment('Latest completed recording file')->after('record_duration');
            }

            // Status columns
            if (!Schema::hasColumn('channels', 'push_status')) {
                $table->enum('push_status', ['idle','starting','live','fallback','error','stopped'])
                      ->default('idle')->after('stream_status');
            }
            if (!Schema::hasColumn('channels', 'dvr_status')) {
                $table->enum('dvr_status', ['idle','starting','recording','error'])
                      ->default('idle')->after('push_status');
            }
            if (!Schema::hasColumn('channels', 'record_status')) {
                $table->enum('record_status', ['idle','recording','finishing','error'])
                      ->default('idle')->after('dvr_status');
            }

            // Process IDs
            if (!Schema::hasColumn('channels', 'push_pid')) {
                $table->unsignedBigInteger('push_pid')->nullable()->comment('Push ffmpeg PID')->after('pid');
            }
            if (!Schema::hasColumn('channels', 'record_pid')) {
                $table->unsignedBigInteger('record_pid')->nullable()->comment('Recording ffmpeg PID')->after('push_pid');
            }

            // Retry tracking
            if (!Schema::hasColumn('channels', 'retry_count')) {
                $table->unsignedSmallInteger('retry_count')->default(0)->after('record_pid');
            }
            if (!Schema::hasColumn('channels', 'last_error')) {
                $table->text('last_error')->nullable()->after('retry_count');
            }

            // Extend stream_status enum to include new states
            // MySQL requires a full column redefinition to change enum values
            // We use a raw statement for safety
        });

        // Extend stream_status enum if needed (MySQL specific)
        $currentType = $this->getColumnType('channels', 'stream_status');
        if ($currentType && !str_contains($currentType, 'offline')) {
            \DB::statement(
                "ALTER TABLE channels MODIFY COLUMN stream_status "
                . "ENUM('idle','starting','live','offline','fallback','error','stopped') "
                . "NOT NULL DEFAULT 'idle'"
            );
        }
    }

    public function down(): void
    {
        // Intentionally not reversing column additions to protect data
    }

    private function getColumnType(string $table, string $column): ?string
    {
        try {
            $result = \DB::selectOne(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?",
                [$table, $column]
            );
            return $result?->COLUMN_TYPE;
        } catch (\Throwable) {
            return null;
        }
    }
};
