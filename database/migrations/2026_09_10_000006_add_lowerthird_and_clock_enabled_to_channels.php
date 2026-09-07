<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            if (! Schema::hasColumn('channels', 'clock_enabled')) {
                $table->boolean('clock_enabled')->default(true)->after('clock_color');
            }
            if (! Schema::hasColumn('channels', 'lowerthird_enabled')) {
                $table->boolean('lowerthird_enabled')->default(true)->after('clock_enabled');
            }
            if (! Schema::hasColumn('channels', 'lowerthird_position')) {
                $table->string('lowerthird_position', 20)->nullable()->default('bottom-left')->after('lowerthird_enabled');
            }
            if (! Schema::hasColumn('channels', 'lowerthird_fontsize')) {
                $table->integer('lowerthird_fontsize')->nullable()->default(20)->after('lowerthird_position');
            }
            if (! Schema::hasColumn('channels', 'lowerthird_font_color')) {
                $table->string('lowerthird_font_color', 30)->nullable()->default('#ffffff')->after('lowerthird_fontsize');
            }
            if (! Schema::hasColumn('channels', 'lowerthird_bg_color')) {
                $table->string('lowerthird_bg_color', 30)->nullable()->default('#334155')->after('lowerthird_font_color');
            }
            if (! Schema::hasColumn('channels', 'lowerthird_bg_opacity')) {
                $table->integer('lowerthird_bg_opacity')->nullable()->default(80)->after('lowerthird_bg_color');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn([
                'clock_enabled',
                'lowerthird_enabled', 'lowerthird_position', 'lowerthird_fontsize',
                'lowerthird_font_color', 'lowerthird_bg_color', 'lowerthird_bg_opacity',
            ]);
        });
    }
};
