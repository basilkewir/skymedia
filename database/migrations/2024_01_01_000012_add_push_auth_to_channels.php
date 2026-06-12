<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            if (!Schema::hasColumn('channels', 'push_username')) {
                $table->string('push_username')->nullable()->after('push_stream_key');
            }
            if (!Schema::hasColumn('channels', 'push_password')) {
                $table->string('push_password')->nullable()->after('push_username');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['push_username', 'push_password']);
        });
    }
};
