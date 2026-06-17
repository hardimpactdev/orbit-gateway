<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_tools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('name');
            $table->string('expected_state')->default('installed');
            $table->string('expected_version')->nullable();
            $table->json('config')->nullable();
            $table->text('credentials')->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'name']);
            $table->index(['name', 'expected_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_tools');
    }
};
