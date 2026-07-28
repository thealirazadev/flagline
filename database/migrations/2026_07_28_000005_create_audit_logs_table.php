<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('flag_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('environment_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('action', 50)->index();
            // Text plus array casts: the schema must run on SQLite and PostgreSQL alike.
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->unsignedInteger('ruleset_version')->nullable();
            // Insert-only rows, so there is a created_at and no updated_at.
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
