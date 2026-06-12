<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            if (!Schema::hasColumn('channels', 'playout_pid')) {
                $table->unsignedInteger('playout_pid')->nullable()->after('push_pid');
            }
            if (!Schema::hasColumn('channels', 'playout_status')) {
                $table->string('playout_status', 20)->default('idle')->after('playout_pid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['playout_pid', 'playout_status']);
        });
    }
};
