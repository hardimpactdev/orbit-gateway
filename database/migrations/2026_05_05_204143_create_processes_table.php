<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('name');
            $table->text('command');
            $table->string('restart_policy')->default('never');
            $table->string('crash_notification')->default('none');
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['app_id', 'name']);
            $table->index(['app_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processes');
    }
};
