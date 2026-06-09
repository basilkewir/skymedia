<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->integer('push_pid')->nullable()->after('dvr_pid');
            $table->integer('retry_count')->default(0)->after('push_pid');
            $table->text('last_error')->nullable()->after('retry_count');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['push_pid', 'retry_count', 'last_error']);
        });
    }
};
