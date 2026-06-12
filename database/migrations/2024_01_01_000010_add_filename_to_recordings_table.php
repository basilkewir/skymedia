<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recordings') && !Schema::hasColumn('recordings', 'filename')) {
            Schema::table('recordings', function (Blueprint $table) {
                $table->string('filename')->after('filepath')->default('');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('recordings', 'filename')) {
            Schema::table('recordings', function (Blueprint $table) {
                $table->dropColumn('filename');
            });
        }
    }
};
