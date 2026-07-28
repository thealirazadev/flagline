<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('sdk_key', 64)->unique();
            $table->text('signing_secret');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environments');
    }
};
