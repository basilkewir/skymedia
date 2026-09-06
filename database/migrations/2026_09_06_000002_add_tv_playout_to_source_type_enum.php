<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE channels MODIFY COLUMN source_type ENUM('hls','udp','mpegts','rtmp','srt','youtube','tv_playout') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE channels MODIFY COLUMN source_type ENUM('hls','udp','mpegts','rtmp','srt','youtube') NOT NULL");
        }
    }
};
