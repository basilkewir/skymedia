<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('ingest_mode', 10)->default('pull')->after('source_type');
            $table->unsignedSmallInteger('ingest_port')->nullable()->unique()->after('source_url');
            $table->string('rtmp_input_key')->nullable()->after('source_url');
            $table->unsignedInteger('relay_pid')->nullable()->after('rtmp_input_key');
            $table->string('fallback_vod_name')->nullable()->after('fallback_recording_path');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropUnique(['ingest_port']);
            $table->dropColumn(['ingest_mode', 'ingest_port', 'rtmp_input_key', 'relay_pid', 'fallback_vod_name']);
        });
    }
};
