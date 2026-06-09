<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            if (!Schema::hasColumn('channels', 'push_pid')) {
                $table->unsignedInteger('push_pid')->nullable()->after('dvr_pid');
            }
            if (!Schema::hasColumn('channels', 'retry_count')) {
                $table->unsignedInteger('retry_count')->default(0)->after('push_pid');
            }
            if (!Schema::hasColumn('channels', 'last_error')) {
                $table->text('last_error')->nullable()->after('retry_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['push_pid', 'retry_count', 'last_error']);
        });
    }
};
