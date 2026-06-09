<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            if (!Schema::hasColumn('channels', 'push_status')) {
                $table->string('push_status')->default('idle')->after('stream_status');
            }
            if (!Schema::hasColumn('channels', 'dvr_status')) {
                $table->string('dvr_status')->default('idle')->after('push_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['push_status', 'dvr_status']);
        });
    }
};
