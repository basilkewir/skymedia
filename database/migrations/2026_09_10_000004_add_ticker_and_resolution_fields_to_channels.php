<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Ticker customization
            $table->string('ticker_bg_color', 30)->nullable()->default('#000000')->after('ticker_text');
            $table->integer('ticker_bg_opacity')->nullable()->default(65)->after('ticker_bg_color');
            $table->integer('ticker_font_size')->nullable()->default(24)->after('ticker_bg_opacity');
            $table->string('ticker_font_color', 30)->nullable()->default('white')->after('ticker_font_size');
            $table->integer('ticker_speed')->nullable()->default(80)->after('ticker_font_color');
            $table->string('ticker_position', 20)->nullable()->default('bottom')->after('ticker_speed');
            // Output resolution for TV playout
            $table->string('output_resolution', 20)->nullable()->default('1920x1080')->after('clock_color');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn([
                'ticker_bg_color', 'ticker_bg_opacity', 'ticker_font_size',
                'ticker_font_color', 'ticker_speed', 'ticker_position',
                'output_resolution',
            ]);
        });
    }
};
