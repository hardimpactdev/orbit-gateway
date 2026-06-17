<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $timestamp = now();
        $pendingAssignments = [];

        DB::table('node_role as gateway_roles')
            ->join('nodes', 'nodes.id', '=', 'gateway_roles.node_id')
            ->leftJoin('node_role as vpn_roles', function ($join): void {
                $join->on('vpn_roles.node_id', '=', 'gateway_roles.node_id')
                    ->where('vpn_roles.role', '=', NodeRoleName::Vpn->value);
            })
            ->where('gateway_roles.role', NodeRoleName::Gateway->value)
            ->where('gateway_roles.status', NodeRoleStatus::Active->value)
            ->where('nodes.status', 'active')
            ->whereNull('vpn_roles.id')
            ->orderBy('gateway_roles.id')
            ->select([
                'gateway_roles.node_id',
                'nodes.gateway_endpoint',
                'nodes.host',
            ])
            ->lazy()
            ->each(function (object $assignment) use ($timestamp, &$pendingAssignments): void {
                $pendingAssignments[] = [
                    'node_id' => $assignment->node_id,
                    'role' => NodeRoleName::Vpn->value,
                    'status' => NodeRoleStatus::Active->value,
                    'settings' => json_encode([
                        'public_endpoint' => $assignment->gateway_endpoint ?: $assignment->host,
                        'wireguard_cidr' => '10.6.0.0/24',
                        'wireguard_port' => 51820,
                        'dns_ip' => '10.6.0.1',
                    ], JSON_THROW_ON_ERROR),
                    'last_error' => null,
                    'converged_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

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
