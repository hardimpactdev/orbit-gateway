<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_instance_env_variables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('secret')->default(false);
            $table->timestamps();

            $table->unique(['app_instance_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_instance_env_variables');
    }
};
