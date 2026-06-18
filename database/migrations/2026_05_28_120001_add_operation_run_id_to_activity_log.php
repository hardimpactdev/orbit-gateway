<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table): void {
                $table->foreignUuid('operation_run_id')
                    ->nullable()
                    ->after('batch_uuid')
                    ->constrained('operation_runs')
                    ->nullOnDelete();
            });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table): void {
                $table->dropForeign(['operation_run_id']);
                $table->dropColumn('operation_run_id');
            });
    }
};
