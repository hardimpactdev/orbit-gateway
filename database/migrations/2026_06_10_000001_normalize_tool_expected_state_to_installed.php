<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('node_tools')
            ->whereIn('expected_state', ['running', 'stopped'])
            ->update(['expected_state' => 'installed']);
    }

    public function down(): void
    {
        // Not reversible: the original per-row lifecycle state is not recoverable.
    }
};
