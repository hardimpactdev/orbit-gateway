<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\AppWebSocketBinding;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

final readonly class WebSocketProxyDoctorProbe
{
    public const string RouterRouteKey = 'proxy.websocket.router_route_missing';

    public const string RouterRouteOrphanedKey = 'proxy.websocket.router_route_orphaned';

    public const string PublicRouteKey = 'proxy.websocket.public_route_missing';

    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private WebSocketRouteRegistrar $routeRegistrar,
    ) {}

    /**
     * @return list<DriftEntry>
     */
    public function drift(Node $node): array
    {
        return [
            ...$this->routerRouteDrift($node),
            ...$this->routerRouteOrphanedDrift($node),
            ...$this->publicRouteDrift($node),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function restore(Node $node, DriftEntry $entry): ?array
    {
        if ($entry->key === self::RouterRouteKey) {
            $route = $this->routeRegistrar->syncServiceRoute();

            return [
                'family' => 'proxy',
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'fix',
                'status' => 'completed',
                'summary' => 'Re-synced private WebSocket router route from gateway intent.',
                'details' => [
                    'domain' => $route->domain,
                ],
            ];
        }

        if ($entry->key === self::RouterRouteOrphanedKey) {
            ProxyRoute::query()->where('domain', WebSocketRouteRegistrar::ServiceDomain)->delete();

            return [
                'family' => 'proxy',
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'fix',
                'status' => 'completed',
                'summary' => 'Removed orphaned websocket.orbit service route row and its rendered artifacts.',
                'details' => [
                    'domain' => WebSocketRouteRegistrar::ServiceDomain,
                ],
            ];
        }

        if ($entry->key !== self::PublicRouteKey) {
            return null;
        }

        $bindingId = $this->integerDetail($entry, 'binding_id');

        if ($bindingId === null) {
            return null;
        }

        $binding = AppWebSocketBinding::query()->find($bindingId);

        if (! $binding instanceof AppWebSocketBinding) {
            return null;
        }

        $this->routeRegistrar->syncPublicHosts($binding);

        return [
            'family' => 'proxy',
            'node' => $node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => 'Re-synced public WebSocket ingress routes from gateway intent.',
            'details' => [
                'binding_id' => $binding->id,
            ],
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function routerRouteDrift(Node $node): array
    {
        if (! $this->nodeRoleAssignments->nodeCanServeRouter($node)) {
            return [];
        }

        if ($this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::WebSocket->value) === []) {
            return [];
        }

        try {
            $intent = $this->routeRegistrar->serviceRouteIntent();
        } catch (Throwable $e) {
            return [
                new DriftEntry(
                    family: 'proxy',
                    key: self::RouterRouteKey,
                    kind: DriftKind::Unverifiable,
                    summary: "WebSocket service route intent cannot be resolved for router node {$node->name}.",
                    detail: [
                        'domain' => WebSocketRouteRegistrar::ServiceDomain,
                        'reason' => $e->getMessage(),
                    ],
                ),
            ];
        }

        if ($intent->node_id !== $node->id) {
            return [];
        }

        return $this->routeDrift(
            intent: $intent,
            key: self::RouterRouteKey,
            missingSummary: 'WebSocket service route websocket.orbit is missing from gateway proxy registry.',
            mismatchSummary: 'WebSocket service route websocket.orbit differs from gateway WebSocket route intent.',
        );
    }

    /**
     * Detect orphaned websocket.orbit service route rows.
     *
     * Fires when the node is a router, the websocket.orbit route row exists,
     * but no active websocket role assignment remains in the topology.
     *
     * @return list<DriftEntry>
     */
    private function routerRouteOrphanedDrift(Node $node): array
    {
        if (! $this->nodeRoleAssignments->nodeCanServeRouter($node)) {
            return [];
        }

        if ($this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::WebSocket->value) !== []) {
            return [];
        }

        $route = ProxyRoute::query()
            ->where('domain', WebSocketRouteRegistrar::ServiceDomain)
            ->first();

        if (! $route instanceof ProxyRoute) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'proxy',
                key: self::RouterRouteOrphanedKey,
                kind: DriftKind::Extra,
                summary: 'The websocket.orbit service route row exists but no active websocket role assignment remains.',
                detail: [
                    'domain' => WebSocketRouteRegistrar::ServiceDomain,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function publicRouteDrift(Node $node): array
    {
        if (! $this->nodeRoleAssignments->nodeCanServeIngress($node)) {
            return [];
        }

        $drift = [];
        /** @var Collection<int, AppWebSocketBinding> $bindings */
        $bindings = AppWebSocketBinding::query()
            ->with('app.node')
            ->where('enabled', true)
            ->get();

        foreach ($bindings as $binding) {
            try {
                $intents = $this->routeRegistrar->publicRouteIntents($binding);
            } catch (Throwable) {
                continue;
            }

            foreach ($intents as $intent) {
                if ($intent->node_id !== $node->id) {
                    continue;
                }

                $drift = [
                    ...$drift,
                    ...$this->routeDrift(
                        intent: $intent,
                        key: self::PublicRouteKey,
                        missingSummary: "WebSocket public route {$intent->domain} is missing from gateway proxy registry.",
                        mismatchSummary: "WebSocket public route {$intent->domain} differs from gateway WebSocket route intent.",
                        detail: [
                            'binding_id' => $binding->id,
                            'app_id' => $binding->app_id,
                        ],
                    ),
                ];
            }
        }

        return $drift;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return list<DriftEntry>
     */
    private function routeDrift(ProxyRoute $intent, string $key, string $missingSummary, string $mismatchSummary, array $detail = []): array
    {
        $route = ProxyRoute::query()
            ->where('domain', $intent->domain)
            ->first();

        $baseDetail = [
            ...$detail,
            'domain' => $intent->domain,
            'expected_node_id' => $intent->node_id,
            'expected_owner_type' => $intent->owner_type,
            'expected_kind' => $intent->kind,
        ];

        if (! $route instanceof ProxyRoute) {
            return [
                new DriftEntry(
                    family: 'proxy',
                    key: $key,
                    kind: DriftKind::Missing,
                    summary: $missingSummary,
                    detail: $baseDetail,
                ),
            ];
        }

        $reason = $this->mismatchReason($route, $intent);

        if ($reason === null) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'proxy',
                key: $key,
                kind: DriftKind::Divergent,
                summary: $mismatchSummary,
                detail: [
                    ...$baseDetail,
                    'reason' => $reason,
                    'observed_node_id' => $route->node_id,
                    'observed_owner_type' => $route->owner_type,
                    'observed_kind' => $route->kind,
                    'expected_source_hash' => $intent->source_hash,
                    'observed_source_hash' => $route->source_hash,
                ],
            ),
        ];
    }

    private function mismatchReason(ProxyRoute $route, ProxyRoute $intent): ?string
    {
        if ($route->node_id !== $intent->node_id) {
            return 'node_mismatch';
        }

        if ($route->app_id !== $intent->app_id) {
            return 'app_mismatch';
        }

        if ($route->workspace_id !== $intent->workspace_id) {
            return 'workspace_mismatch';
        }

        if ($route->owner_type !== $intent->owner_type) {
            return 'owner_type_mismatch';
        }

        if ($route->kind !== $intent->kind) {
            return 'kind_mismatch';
        }

        if ($route->config !== $intent->config) {
            return 'config_mismatch';
        }

        if ($route->source_hash !== $intent->source_hash) {
            return 'source_hash_mismatch';
        }

        return null;
    }

    private function integerDetail(DriftEntry $entry, string $key): ?int
    {
        $detail = $entry->detail ?? [];
        $value = $detail[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
