<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            if (! Schema::hasColumn('channels', 'logo_scale')) {
                // Logo size as percentage of video width (1–50). Default 12%.
                $table->unsignedTinyInteger('logo_scale')->default(12)->after('logo_position');
            }
            if (! Schema::hasColumn('channels', 'logo_enabled')) {
                $table->boolean('logo_enabled')->default(true)->after('logo_scale');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['logo_scale', 'logo_enabled']);
        });
    }
};
