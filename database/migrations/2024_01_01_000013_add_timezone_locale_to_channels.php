<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            if (!Schema::hasColumn('channels', 'timezone')) {
                $table->string('timezone', 50)->default('UTC')->after('segment_duration');
            }
            if (!Schema::hasColumn('channels', 'locale')) {
                $table->string('locale', 10)->default('en')->after('timezone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'locale']);
        });
    }
};
