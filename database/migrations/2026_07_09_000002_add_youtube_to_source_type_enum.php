<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE channels MODIFY COLUMN source_type ENUM('hls','udp','mpegts','rtmp','srt','youtube') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE channels MODIFY COLUMN source_type ENUM('hls','udp','mpegts','rtmp','srt') NOT NULL");
    }
};
