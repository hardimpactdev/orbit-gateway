<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_update_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('operation_run_id')
                ->unique()
                ->constrained('operation_runs')
                ->cascadeOnDelete();
            $table->string('target_version');
            $table->string('gateway_image', 512);
            $table->string('manifest_source', 512);
            $table->string('manifest_version');
            $table->json('manifest_snapshot');
            $table->json('cli_artifacts');
            $table->json('role_images');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_update_plans');
    }
};
