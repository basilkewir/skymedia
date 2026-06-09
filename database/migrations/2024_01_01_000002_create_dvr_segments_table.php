<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dvr_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('filepath');
            $table->float('duration');
            $table->integer('sequence');
            $table->bigInteger('filesize')->default(0);
            $table->timestamp('recorded_at');
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->index(['channel_id', 'sequence']);
            $table->index(['channel_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dvr_segments');
    }
};
