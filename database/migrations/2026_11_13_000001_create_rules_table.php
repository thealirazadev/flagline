<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flag_environment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('priority');
            $table->string('description')->nullable();
            $table->foreignId('serve_variant_id')->nullable()->constrained('variants');
            $table->text('serve_rollout')->nullable();
            $table->timestamps();

            $table->unique(['flag_environment_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rules');
    }
};
