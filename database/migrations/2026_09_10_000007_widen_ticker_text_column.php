<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // varchar(500) overflows when many ticker items are joined — widen to TEXT
            $table->text('ticker_text')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('ticker_text', 500)->nullable()->change();
        });
    }
};
