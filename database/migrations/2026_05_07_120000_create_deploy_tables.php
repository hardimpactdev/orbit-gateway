<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->string('latest_deployment_status')->nullable()->after('agent_ide_config');
            $table->foreignId('latest_deployment_run_id')->nullable()->after('latest_deployment_status');
        });

        Schema::create('deploy_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('title');
            $table->text('command');
            $table->unsignedInteger('sort_order');
            $table->unsignedInteger('timeout_seconds')->default(600);
            $table->unsignedInteger('retention')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'sort_order']);
            $table->index(['app_id', 'title']);
        });

        Schema::create('deployment_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('status');
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'started_at']);
            $table->index(['app_id', 'status']);
        });

        Schema::create('deployment_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deployment_run_id')->constrained('deployment_runs')->cascadeOnDelete();
            $table->foreignId('deploy_step_id')->nullable()->constrained('deploy_steps')->nullOnDelete();
            $table->string('title');
            $table->text('command');
            $table->string('status');
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['deployment_run_id', 'deploy_step_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_run_steps');
        Schema::dropIfExists('deployment_runs');
        Schema::dropIfExists('deploy_steps');

        Schema::table('apps', function (Blueprint $table): void {
            $table->dropColumn(['latest_deployment_status', 'latest_deployment_run_id']);
        });
    }
};
