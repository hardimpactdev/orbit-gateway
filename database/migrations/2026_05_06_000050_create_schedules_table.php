<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('schedule_key')->unique();
            $table->string('name');
            $table->string('scope');
            $table->foreignId('app_id')->nullable()->constrained('apps')->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('nodes')->cascadeOnDelete();
            $table->string('target_name');
            $table->string('interval');
            $table->string('timezone');
            $table->string('execution_type');
            $table->text('execution_value');
            $table->boolean('enabled')->default(true);
            $table->string('status')->default('expected');
            $table->timestamps();

            $table->index(['scope', 'target_name', 'name']);
            $table->index(['app_id', 'name']);
            $table->index(['node_id', 'name']);
            $table->index(['enabled', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
