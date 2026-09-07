<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Lower-third free X/Y positioning (pixel coords, same system as logo_position)
            $table->integer('lowerthird_x')->nullable()->default(null)->after('lowerthird_position');
            $table->integer('lowerthird_y')->nullable()->default(null)->after('lowerthird_x');
            // Ticker items as JSON array [{text, color, bg_color}]
            $table->json('ticker_items')->nullable()->after('ticker_position');
            // Ticker label (prefix shown in accent color before the scrolling text)
            $table->string('ticker_label', 200)->nullable()->after('ticker_items');
            $table->string('ticker_label_color', 30)->nullable()->default('#ff0000')->after('ticker_label');
            $table->string('ticker_label_bg', 30)->nullable()->default('#ffffff')->after('ticker_label_color');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['lowerthird_x', 'lowerthird_y', 'ticker_items', 'ticker_label', 'ticker_label_color', 'ticker_label_bg']);
        });
    }
};
