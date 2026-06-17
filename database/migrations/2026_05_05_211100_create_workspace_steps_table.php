<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('phase')->default('setup');
            $table->unsignedInteger('sort_order');
            $table->text('command');
            $table->unsignedInteger('timeout_seconds')->default(600);
            $table->timestamps();

            $table->index(['app_id', 'phase', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_steps');
    }
};
