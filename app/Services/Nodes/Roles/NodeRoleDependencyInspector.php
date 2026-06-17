<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\ProxyRoute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class NodeRoleDependencyInspector
{
    /**
     * @var array<string, string>
     */
    private const array AppRoleEnvironments = [
        NodeRoleName::AppDevelopment->value => 'development',
        NodeRoleName::AppProduction->value => 'production',
    ];

    /**
     * @var list<string>
     */
    private const array DatabaseProcessDefinitions = [
        'mysql',
        'postgres',
        'redis',
    ];

    /**
     * @return list<string>
     */
    public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
    {
        if (array_key_exists($assignment->role, self::AppRoleEnvironments)) {
            return $this->appRoleDependentSummaries($node, $assignment->role);
        }

        if ($assignment->role === NodeRoleName::Database->value) {
            return $this->databaseDependentSummaries($node);
        }

        if ($assignment->role === NodeRoleName::Ingress->value) {
            return $this->ingressDependentSummaries($node);
        }

        return [];
    }

    public function removeOrbitOwnedDependents(Node $node, NodeRoleAssignment $assignment): void
    {
        if (array_key_exists($assignment->role, self::AppRoleEnvironments)) {
            $this->removeAppRoleDependents($node, $assignment->role);

            return;
        }

        if ($assignment->role === NodeRoleName::Database->value) {
            $this->removeDatabaseDependents($node);

            return;
        }

        if ($assignment->role === NodeRoleName::Ingress->value) {
            $this->removeIngressDependents($node);
        }
    }

    /**
     * @return list<string>
     */
    private function appRoleDependentSummaries(Node $node, string $role): array
    {
        $environment = self::AppRoleEnvironments[$role];
        $count = App::query()
            ->where('node_id', $node->id)
            ->where('environment', $environment)
            ->count();

        if ($count === 0) {
            return [];
        }

        return ["{$count} {$environment} app ".($count === 1 ? 'record' : 'records')];
    }

    /**
     * @return list<string>
     */
    private function databaseDependentSummaries(Node $node): array
    {
        $count = Process::query()
            ->ownedBy($node)
            ->whereIn('runtime_config->definition', self::DatabaseProcessDefinitions)
            ->count();

        if ($count === 0) {
            return [];
        }

        return ["{$count} database process ".($count === 1 ? 'record' : 'records')];
    }

    private function removeAppRoleDependents(Node $node, string $role): void
    {
        $environment = self::AppRoleEnvironments[$role];

        DB::transaction(function () use ($node, $environment): void {
            $appIds = App::query()
                ->where('node_id', $node->id)
                ->where('environment', $environment)
                ->pluck('id')
                ->all();

            if ($appIds === []) {
                return;
            }

            ProxyRoute::query()
                ->whereIn('app_id', $appIds)
                ->delete();

            App::query()
                ->whereIn('id', $appIds)
                ->delete();
        });
    }

    private function removeDatabaseDependents(Node $node): void
    {
        Process::query()
            ->ownedBy($node)
            ->whereIn('runtime_config->definition', self::DatabaseProcessDefinitions)
            ->delete();
    }

    /**
     * @return list<string>
     */
    private function ingressDependentSummaries(Node $node): array
    {
        $count = $this->ingressProxyRouteQuery($node)->count();

        if ($count === 0) {
            return [];
        }

        return ["{$count} public proxy route ".($count === 1 ? 'record' : 'records')];
    }

    private function removeIngressDependents(Node $node): void
    {
        $this->ingressProxyRouteQuery($node)->delete();
    }

    private function ingressProxyRouteQuery(Node $node): Builder
    {
        return ProxyRoute::query()
            ->where('node_id', $node->id)
            ->where('config->placement', 'ingress')
            ->whereHas('app', fn (Builder $query): Builder => $query->where('environment', 'production'))
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->where('owner_type', 'app')
                            ->where('kind', 'app');
                    })
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('owner_type', 'workspace')
                            ->where('kind', 'workspace');
                    });
            });
    }
}
