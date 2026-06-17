<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->restrictOnDelete();
            $table->string('name');
            $table->string('direction');
            $table->string('action');
            $table->string('source')->default('any');
            $table->string('destination')->nullable();
            $table->string('port');
            $table->string('protocol');
            $table->text('reason')->nullable();
            $table->string('source_hash', 64);
            $table->timestamps();

            $table->unique(['node_id', 'name']);
            $table->index(['node_id', 'direction', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_rules');
    }
};
