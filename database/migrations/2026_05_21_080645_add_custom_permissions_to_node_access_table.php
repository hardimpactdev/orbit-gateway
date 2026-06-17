<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_access', function (Blueprint $table): void {
            $table->json('custom_permissions')->default(json_encode([]))->after('permissions');
        });

        DB::table('node_access')
            ->whereColumn('consumer_node_id', 'serving_node_id')
            ->update(['custom_permissions' => DB::raw('permissions')]);
    }

    public function down(): void
    {
        Schema::table('node_access', function (Blueprint $table): void {
            $table->dropColumn('custom_permissions');
        });
    }
};
