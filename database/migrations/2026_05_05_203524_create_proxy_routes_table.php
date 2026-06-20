<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->restrictOnDelete();
            $table->string('domain')->unique();
            $table->foreignId('app_id')->nullable()->constrained('apps')->nullOnDelete();
            $table->string('owner_type');
            $table->string('kind');
            $table->string('source_hash', 64);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->index(['node_id', 'kind']);
            $table->index(['app_id', 'owner_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_routes');
    }
};
