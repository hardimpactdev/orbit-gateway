<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_runtime_mounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('source', 512);
            $table->string('target', 512);
            $table->boolean('read_only')->default(true);
            $table->timestamps();

            $table->unique(['app_id', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_runtime_mounts');
    }
};
