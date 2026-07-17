<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('channels', 'excluded_recordings')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->json('excluded_recordings')->nullable()->after('fallback_vod_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('channels', 'excluded_recordings')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->dropColumn('excluded_recordings');
            });
        }
    }
};
