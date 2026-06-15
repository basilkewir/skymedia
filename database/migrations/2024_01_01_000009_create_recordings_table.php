<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('recordings')) {
            Schema::create('recordings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
                $table->string('filepath');
                $table->string('filename')->default('');
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
        }

        Schema::table('channels', function (Blueprint $table) {
            if (!Schema::hasColumn('channels', 'record_duration')) {
                $table->unsignedInteger('record_duration')->default(0)
                      ->comment('seconds per recording, 0 = disabled')->after('dvr_duration');
            }
            if (!Schema::hasColumn('channels', 'record_pid')) {
                $table->unsignedInteger('record_pid')->nullable()->after('push_pid');
            }
            if (!Schema::hasColumn('channels', 'record_status')) {
                $table->string('record_status', 20)->default('idle')->after('dvr_status');
            }
            if (!Schema::hasColumn('channels', 'fallback_recording_path')) {
                $table->string('fallback_recording_path', 1000)->nullable()
                      ->after('dvr_path');
            }
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
