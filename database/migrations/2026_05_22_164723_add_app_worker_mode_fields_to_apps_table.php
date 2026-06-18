<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->boolean('worker_enabled')->default(false)->after('runtime_kind');
            $table->json('worker_config')->nullable()->after('worker_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->dropColumn(['worker_enabled', 'worker_config']);
        });
    }
};
