<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('push_destinations')) return;

        Schema::create('push_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('protocol', 10)->default('rtmp')->comment('rtmp|srt');
            $table->string('url', 500);
            $table->string('stream_key', 255);
            $table->string('username', 255)->nullable();
            $table->string('password', 255)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('pid')->nullable()->comment('Push ffmpeg PID');
            $table->string('status', 20)->default('idle');
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->index(['channel_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_destinations');
    }
};
