<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flag_id')->constrained()->cascadeOnDelete();
            $table->string('value', 255);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['flag_id', 'value']);
            $table->unique(['flag_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variants');
    }
};
