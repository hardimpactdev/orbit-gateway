<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxy_routes', function (Blueprint $table): void {
            $table->foreignId('workspace_id')
                ->nullable()
                ->after('app_id')
                ->constrained('workspaces')
                ->nullOnDelete();

            $table->index(['workspace_id', 'owner_type']);
        });
    }

    public function down(): void
    {
        Schema::table('proxy_routes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
