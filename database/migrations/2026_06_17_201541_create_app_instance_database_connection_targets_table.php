<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_instance_database_connection_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('database_connection_id')->constrained('database_connections')->cascadeOnDelete();
            $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
            $table->string('env_prefix');
            $table->timestamps();

            $table->unique(['app_instance_id', 'env_prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_instance_database_connection_targets');
    }
};
