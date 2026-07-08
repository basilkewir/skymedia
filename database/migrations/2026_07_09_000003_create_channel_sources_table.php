<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('source_url');
            $table->string('source_type', 20)->default('hls');
            $table->unsignedSmallInteger('priority')->default(0)->comment('Lower = tried first');
            $table->boolean('is_active')->default(true);
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['channel_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_sources');
    }
};
