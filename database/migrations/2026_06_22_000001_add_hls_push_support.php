<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convert enum to string so 'hls' is accepted on all drivers.
        // Laravel's change() recreates the table on SQLite and uses ALTER on MySQL.
        Schema::table('channels', function (Blueprint $table) {
            $table->string('push_protocol', 10)->default('rtmp')->change();
        });

        Schema::table('channels', function (Blueprint $table) {
            if (! Schema::hasColumn('channels', 'push_hls_segment_duration')) {
                $table->unsignedTinyInteger('push_hls_segment_duration')
                    ->nullable()
                    ->after('push_stream_key')
                    ->comment('HLS push segment duration in seconds');
            }
            if (! Schema::hasColumn('channels', 'push_hls_list_size')) {
                $table->unsignedSmallInteger('push_hls_list_size')
                    ->nullable()
                    ->after('push_hls_segment_duration')
                    ->comment('HLS push playlist window (0 = keep all)');
            }
        });

        // PushDestination protocol also needs to accept 'hls'.
        if (Schema::hasTable('push_destinations')) {
            Schema::table('push_destinations', function (Blueprint $table) {
                $table->string('protocol', 10)->default('rtmp')->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['push_hls_segment_duration', 'push_hls_list_size']);
        });

        // MySQL: restore enum. SQLite: string is functionally identical.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE channels MODIFY COLUMN push_protocol ENUM('rtmp','srt') DEFAULT 'rtmp'");
        }

        if (Schema::hasTable('push_destinations') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE push_destinations MODIFY COLUMN protocol ENUM('rtmp','srt') DEFAULT 'rtmp'");
        }
    }
};
