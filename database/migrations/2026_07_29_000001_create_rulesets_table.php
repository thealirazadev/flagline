<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rulesets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('schema_version');
            // The exact bytes served and signed; never re-serialized.
            $table->longText('body');
            $table->string('etag', 64);
            $table->string('signature', 80);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            // Insert-only rows, so there is a created_at and no updated_at.
            $table->timestamp('created_at')->nullable();

            // Claims the version inside the publish transaction and backs the
            // "latest version for this environment" serve query.
            $table->unique(['environment_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rulesets');
    }
};
