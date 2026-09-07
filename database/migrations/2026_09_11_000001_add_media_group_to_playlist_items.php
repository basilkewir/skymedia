<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            // 'default' = all overlays, 'clean' = no overlays (logo/ticker/clock/lowerthird suppressed)
            $table->string('media_group', 30)->nullable()->default('default')->after('custom_title');
        });
    }

    public function down(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->dropColumn('media_group');
        });
    }
};
