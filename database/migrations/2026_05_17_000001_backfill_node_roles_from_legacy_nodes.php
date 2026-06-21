<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $timestamp = now();
        $pendingAssignments = [];

        DB::table('nodes')
            ->select(['id', 'role', 'environment', 'tld'])
            ->lazyById()
            ->each(function (object $node) use ($timestamp, &$pendingAssignments): void {
                $assignment = match (true) {
                    $node->role === 'gateway' => [
                        'node_id' => $node->id,
                        'role' => 'gateway',
                        'status' => 'active',
                        'settings' => json_encode([], JSON_THROW_ON_ERROR),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                    $node->role === 'app' && $node->environment === 'development' => [
                        'node_id' => $node->id,
                        'role' => 'app-dev',
                        'status' => 'active',
                        'settings' => json_encode(['tld' => $node->tld], JSON_THROW_ON_ERROR),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                    $node->role === 'app' && $node->environment === 'production' => [
                        'node_id' => $node->id,
                        'role' => 'app-prod',
                        'status' => 'active',
                        'settings' => json_encode([], JSON_THROW_ON_ERROR),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                    default => null,
                };

                if ($assignment === null) {
                    return;
                }

                $pendingAssignments[] = $assignment;

                if (count($pendingAssignments) < 500) {
                    return;
                }

                DB::table('node_role')->insertOrIgnore($pendingAssignments);

                $pendingAssignments = [];
            });

        if ($pendingAssignments === []) {
            return;
        }

        DB::table('node_role')->insertOrIgnore($pendingAssignments);
    }

    public function down(): void {}
};
