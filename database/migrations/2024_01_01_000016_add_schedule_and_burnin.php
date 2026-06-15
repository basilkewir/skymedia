<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            if (!Schema::hasColumn('channels', 'schedule_start')) {
                $table->time('schedule_start')->nullable()->after('check_interval')
                    ->comment('Daily auto-start time (server timezone)');
            }
            if (!Schema::hasColumn('channels', 'schedule_stop')) {
                $table->time('schedule_stop')->nullable()->after('schedule_start')
                    ->comment('Daily auto-stop time (server timezone)');
            }
            if (!Schema::hasColumn('channels', 'schedule_days')) {
                $table->string('schedule_days', 20)->default('1234567')->after('schedule_stop')
                    ->comment('Days: 1=Mon..7=Sun, empty=all days');
            }
            if (!Schema::hasColumn('channels', 'recording_burn_timestamp')) {
                $table->boolean('recording_burn_timestamp')->default(false)->after('keep_recordings')
                    ->comment('Burn UTC timestamp into fallback recording');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['schedule_start', 'schedule_stop', 'schedule_days', 'recording_burn_timestamp']);
        });
    }
};
