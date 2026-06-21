<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('operation_run_id')
                ->constrained('operation_runs')
                ->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('event_type');
            $table->json('payload');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['operation_run_id', 'sequence']);
            $table->index(['operation_run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_events');
    }
};
