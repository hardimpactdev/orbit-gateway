<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduler_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('registry_synced_at')->nullable();
            $table->timestamps();

            $table->unique('node_id');
            $table->index('heartbeat_at');
            $table->index('registry_synced_at');
        });

        Schema::create('schedule_locks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('schedule_key');
            $table->string('owner_token');
            $table->timestamp('locked_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'schedule_key']);
            $table->index(['node_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_locks');
        Schema::dropIfExists('scheduler_states');
    }
};
