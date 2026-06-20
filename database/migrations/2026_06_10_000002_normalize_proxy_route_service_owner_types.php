<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Align proxy route owner_type values with the b8c7532f docs contract.
 *
 * - websocket.orbit service route: owner_type 'websocket' → 'router'
 * - s3.orbit service route: owner_type 'tool' → 'router'
 * - public S3 host routes (protocol s3, not the service domain): owner_type 'tool' → 's3'
 */
return new class extends Migration
{
    public function up(): void
    {
        // Websocket service route: owner 'websocket' → 'router'
        DB::table('proxy_routes')
            ->where('domain', 'websocket.orbit')
            ->where('owner_type', 'websocket')
            ->update(['owner_type' => 'router']);

        // S3 service route: owner 'tool' → 'router'
        DB::table('proxy_routes')
            ->where('domain', 's3.orbit')
            ->where('owner_type', 'tool')
            ->update(['owner_type' => 'router']);

        // Public S3 host routes (all remaining rows with owner 'tool' and protocol s3
        // in config are public host routes — the service route was handled above).
        DB::table('proxy_routes')
            ->where('owner_type', 'tool')
            ->whereJsonContains('config->protocol', 's3')
            ->whereJsonContains('config->owner_name', 'rustfs')
            ->update(['owner_type' => 's3']);
    }

    public function down(): void
    {
        // Reverse: router-owned websocket.orbit → 'websocket'
        DB::table('proxy_routes')
            ->where('domain', 'websocket.orbit')
            ->where('owner_type', 'router')
            ->update(['owner_type' => 'websocket']);

        // Reverse: router-owned s3.orbit → 'tool'
        DB::table('proxy_routes')
            ->where('domain', 's3.orbit')
            ->where('owner_type', 'router')
            ->update(['owner_type' => 'tool']);

        // Reverse: public S3 routes → 'tool'
        DB::table('proxy_routes')
            ->where('owner_type', 's3')
            ->whereJsonContains('config->protocol', 's3')
            ->whereJsonContains('config->owner_name', 'rustfs')
            ->update(['owner_type' => 'tool']);
    }
};
