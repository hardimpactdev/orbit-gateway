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
            $table->json('permissions')->default(json_encode(['*']))->after('serving_node_id');
        });

        DB::table('node_access')->update(['permissions' => json_encode(['*'])]);
    }

    public function down(): void
    {
        Schema::table('node_access', function (Blueprint $table): void {
            $table->dropColumn('permissions');
        });
    }
};
