<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_run_id')->constrained('workspace_runs')->cascadeOnDelete();
            $table->foreignId('workspace_step_id')->nullable()->constrained('workspace_steps')->nullOnDelete();
            $table->text('command');
            $table->integer('exit_code')->nullable();
            $table->longText('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_run_id', 'workspace_step_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_run_steps');
    }
};
