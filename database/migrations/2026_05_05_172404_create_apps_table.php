<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('node_id')->constrained('nodes')->restrictOnDelete();
            $table->string('environment')->default('development');
            $table->string('domain')->nullable()->unique();
            $table->string('path');
            $table->string('document_root')->default('public');
            $table->string('repository')->nullable();
            $table->string('php_version')->default('8.5');
            $table->boolean('adopted')->default(false);
            $table->timestamps();

            $table->index(['node_id', 'name']);
            $table->index(['node_id', 'environment']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
