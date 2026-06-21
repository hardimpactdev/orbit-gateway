<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_leases', function (Blueprint $table): void {
            $table->id();
            $table->string('resource_type');
            $table->string('resource_key');
            $table->string('active_resource_key')->nullable()->unique();
            $table->foreignUuid('operation_run_id')
                ->constrained('operation_runs')
                ->cascadeOnDelete();
            $table->string('owner_token');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['resource_type', 'resource_key']);
            $table->index(['operation_run_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_leases');
    }
};
