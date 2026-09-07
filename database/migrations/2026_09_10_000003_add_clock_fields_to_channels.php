<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('clock_position', 20)->nullable()->default('top-left')->after('playlist_loop');
            $table->integer('clock_fontsize')->nullable()->default(28)->after('clock_position');
            $table->string('clock_color', 30)->nullable()->default('white')->after('clock_fontsize');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['clock_position', 'clock_fontsize', 'clock_color']);
        });
    }
};
