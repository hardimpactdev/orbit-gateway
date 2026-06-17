<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_role', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('role');
            $table->string('status')->default('pending');
            $table->json('settings')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('converged_at')->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'role']);
            $table->index(['role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_role');
    }
};
