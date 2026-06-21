<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_analytics_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('public_hosts');
            $table->timestamps();

            $table->unique('app_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_analytics_bindings');
    }
};
