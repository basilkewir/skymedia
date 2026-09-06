<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('filepath');
            $table->double('duration', 12, 4)->comment('Exact duration in seconds from ffprobe');
            $table->unsignedInteger('sort_order')->default(0);
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_items');
    }
};
