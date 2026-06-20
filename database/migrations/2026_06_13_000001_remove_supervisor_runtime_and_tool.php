<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('processes')
            ->where('runtime', 'supervisor')
            ->update(['runtime' => 'systemd']);

        DB::table('node_tools')
            ->where('name', 'supervisor')
            ->delete();
    }
};
