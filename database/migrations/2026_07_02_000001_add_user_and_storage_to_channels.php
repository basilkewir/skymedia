<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            if (!Schema::hasColumn('channels', 'user_id')) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete()
                    ->after('slug');
            }
            if (!Schema::hasColumn('channels', 'storage_quota_bytes')) {
                $table->unsignedBigInteger('storage_quota_bytes')
                    ->nullable()
                    ->after('dvr_path')
                    ->comment('Storage quota in bytes (null = unlimited)');
            }
            if (!Schema::hasColumn('channels', 'storage_used_bytes')) {
                $table->unsignedBigInteger('storage_used_bytes')
                    ->default(0)
                    ->after('storage_quota_bytes')
                    ->comment('Current storage usage in bytes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['storage_quota_bytes', 'storage_used_bytes']);
        });
    }
};
