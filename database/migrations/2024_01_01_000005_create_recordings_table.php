<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            $table->string('filepath');
            $table->string('filename');
            $table->float('duration')->default(0)->comment('Actual recorded duration in seconds');
            $table->bigInteger('filesize')->default(0);
            $table->enum('status', ['recording','completed','failed'])->default('recording');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['channel_id', 'status']);
            $table->index(['channel_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recordings');
    }
};
