<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operation_id')->index();
            $table->string('internal_command')->nullable();
            $table->string('operation_type')->nullable();
            $table->string('lane');
            $table->string('status');
            $table->foreignId('caller_node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->foreignId('target_node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->uuid('correlation_id')->nullable();
            $table->string('queue')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->json('result')->nullable();
            $table->json('error')->nullable();
            $table->text('stdout_summary')->nullable();
            $table->text('stderr_summary')->nullable();
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement(
                "CREATE TRIGGER operation_runs_lane_check
                BEFORE INSERT ON operation_runs
                FOR EACH ROW
                WHEN NEW.lane NOT IN ('host', 'runtime', 'local', 'gateway')
                BEGIN
                    SELECT RAISE(ABORT, 'operation_runs.lane must be one of host, runtime, local, gateway');
                END"
            );

            DB::statement(
                "CREATE TRIGGER operation_runs_lane_check_update
                BEFORE UPDATE ON operation_runs
                FOR EACH ROW
                WHEN NEW.lane NOT IN ('host', 'runtime', 'local', 'gateway')
                BEGIN
                    SELECT RAISE(ABORT, 'operation_runs.lane must be one of host, runtime, local, gateway');
                END"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS operation_runs_lane_check');
            DB::statement('DROP TRIGGER IF EXISTS operation_runs_lane_check_update');
        }

        Schema::dropIfExists('operation_runs');
    }
};
