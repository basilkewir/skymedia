<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('channel_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name');
            $table->string('filepath', 1000);
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('filesize')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('logo_media_id')->nullable()->constrained('channel_media')->nullOnDelete();
            $table->string('logo_position', 20)->default('top-right');
            $table->boolean('ticker_enabled')->default(false);
            $table->string('ticker_text', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channels', fn (Blueprint $t) => $t->dropConstrainedForeignId('logo_media_id'));
        Schema::table('channels', fn (Blueprint $t) => $t->dropColumn(['logo_position', 'ticker_enabled', 'ticker_text']));
        Schema::dropIfExists('channel_media');
    }
};
