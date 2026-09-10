<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jingles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('filepath');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('filesize')->default(0);
            $table->double('duration', 12, 4)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jingles');
    }
};
