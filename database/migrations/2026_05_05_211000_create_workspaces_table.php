<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('php_version')->nullable();
            $table->string('agent_ide')->nullable();
            $table->string('agent_ide_workspace_id')->nullable();
            $table->string('lifecycle_status')->default('expected');
            $table->timestamps();

            $table->unique(['app_id', 'name']);
            $table->index(['app_id', 'lifecycle_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
