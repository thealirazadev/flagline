<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flag_environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('killed')->default(false);
            $table->foreignId('off_variant_id')->constrained('variants');
            $table->foreignId('fallthrough_variant_id')->nullable()->constrained('variants');
            // Text plus an array cast: the schema must run on SQLite and PostgreSQL alike.
            $table->text('fallthrough_rollout')->nullable();
            $table->timestamps();

            $table->unique(['flag_id', 'environment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flag_environments');
    }
};
