<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * PROXY-family doctor probe for S3 routing drift.
 *
 * Execution lane: gateway DB reads only (no remote shell, no SSH, no Docker).
 * All checks compare gateway registry rows against gateway intent built by
 * S3RouteRegistrar intent builders. No node-side introspection is performed.
 *
 * Owns three issue keys:
 *  - proxy.s3.router_route_missing  — s3.orbit route absent or differs from intent
 *  - proxy.s3.router_backend_invalid — s3.orbit route exists but backend pool is
 *    semantically invalid (empty or points to a non-S3 host)
 *  - proxy.s3.public_route_missing  — public ingress S3 route absent or divergent
 *
 * Non-overlap contract (router_route_missing vs router_backend_invalid):
 *  - When the route is ABSENT → only router_route_missing fires.
 *  - When the route exists and INTENT FIELDS differ (node/owner/config/hash) →
 *    only router_route_missing fires (Divergent). Backend content is not checked
 *    in that case because the intent comparison already covers the discrepancy.
 *  - When the route exists, intent matches structurally (source_hash agrees), but
 *    the backend pool content is semantically invalid (empty upstreams, or upstream
 *    host does not end with .s3.orbit or uses the wrong port) →
 *    only router_backend_invalid fires.
 *  These two conditions are mutually exclusive by design: routerBackendDrift
 *  only runs when the route is present, and routerRouteDrift returns early on
 *  any intent mismatch so the backend check never fires alongside it.
 */
final readonly class S3ProxyDoctorProbe
{
    public const string RouterRouteKey = 'proxy.s3.router_route_missing';

    public const string RouterBackendKey = 'proxy.s3.router_backend_invalid';

    public const string RouterRouteOrphanedKey = 'proxy.s3.router_route_orphaned';

    public const string PublicRouteKey = 'proxy.s3.public_route_missing';

    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private S3RouteRegistrar $routeRegistrar,
    ) {}

    /**
     * @return list<DriftEntry>
     */
    public function drift(Node $node): array
    {
        return [
            ...$this->routerRouteDrift($node),
            ...$this->routerBackendDrift($node),
            ...$this->routerRouteOrphanedDrift($node),
            ...$this->publicRouteDrift($node),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function restore(Node $node, DriftEntry $entry): ?array
    {
        if ($entry->key === self::RouterRouteKey || $entry->key === self::RouterBackendKey) {
            $route = $this->routeRegistrar->syncServiceRoute();

            return [
                'family' => 'proxy',
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'fix',
                'status' => 'completed',
                'summary' => 'Re-synced private S3 router route from gateway intent.',
                'details' => [
                    'domain' => $route->domain,
                ],
            ];
        }

        if ($entry->key === self::RouterRouteOrphanedKey) {
            ProxyRoute::query()->where('domain', S3RouteRegistrar::ServiceDomain)->delete();

            return [
                'family' => 'proxy',
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'fix',
                'status' => 'completed',
                'summary' => 'Removed orphaned s3.orbit service route row and its rendered artifacts.',
                'details' => [
                    'domain' => S3RouteRegistrar::ServiceDomain,
                ],
            ];
        }

        if ($entry->key !== self::PublicRouteKey) {
            return null;
        }

        $toolId = $this->integerDetail($entry, 'seaweedfs_tool_id');

        if ($toolId === null) {
            return null;
        }

        $seaweedfs = NodeTool::query()->find($toolId);

        if (! $seaweedfs instanceof NodeTool) {
            return null;
        }

        $this->routeRegistrar->syncPublicHosts($seaweedfs);

        return [
            'family' => 'proxy',
            'node' => $node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => 'Re-synced public S3 ingress routes from gateway intent.',
            'details' => [
                'seaweedfs_tool_id' => $seaweedfs->id,
            ],
        ];
    }

    /**
     * Check whether the private router-owned s3.orbit route exists and matches
     * gateway S3 service-route intent.
     *
     * Gated on: node must be a router AND at least one active s3 node must exist.
     * Reports only when intent->node_id === node->id (this is the owning router).
     * Emits router_route_missing (Missing) when absent, (Divergent) when any intent
     * field differs and the backend pool content is valid.
     *
     * Non-overlap with router_backend_invalid: when the backend pool is semantically
     * invalid (empty or wrong host), routerBackendDrift will have already classified
     * the scenario as backend_invalid, so routerRouteDrift skips reporting in that
     * case to avoid double-reporting. The "bad-backend" scenario always reports
     * router_backend_invalid; "pure intent-drift with valid backends" always reports
     * router_route_missing.
     *
     * @return list<DriftEntry>
     */
    private function routerRouteDrift(Node $node): array
    {
        if (! $this->nodeRoleAssignments->nodeCanServeRouter($node)) {
            return [];
        }

        if ($this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::S3->value) === []) {
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
                    summary: "S3 service route intent cannot be resolved for router node {$node->name}.",
                    detail: [
                        'domain' => S3RouteRegistrar::ServiceDomain,
                        'reason' => $e->getMessage(),
                    ],
                ),
            ];
        }

        if ($intent->node_id !== $node->id) {
            return [];
        }

        $route = ProxyRoute::query()
            ->where('domain', S3RouteRegistrar::ServiceDomain)
            ->first();

        // If the route exists and has an invalid backend pool, routerBackendDrift
        // will classify this as router_backend_invalid. Do not double-report here.
        if ($route instanceof ProxyRoute && $this->hasInvalidBackendPool($route)) {
            return [];
        }

        return $this->routeDrift(
            intent: $intent,
            key: self::RouterRouteKey,
            missingSummary: 'S3 service route s3.orbit is missing from gateway proxy registry.',
            mismatchSummary: 'S3 service route s3.orbit differs from gateway S3 route intent.',
        );
    }

    /**
     * Check whether the s3.orbit route's backend pool is semantically valid.
     *
     * Gated on: node must be a router AND the s3.orbit route must exist.
     * Only fires when the route is present; absence is covered by routerRouteDrift.
     * Reports router_backend_invalid (Divergent) when the upstreams list is empty
     * or any upstream host is not a valid <name>.s3.orbit:8333 SeaweedFS backend.
     *
     * Non-overlap with router_route_missing: this check fires ONLY on backend-content
     * invalidity, regardless of whether source_hash also differs. routerRouteDrift
     * defers to this check and skips when the backend pool is found to be invalid.
     * A "pure intent drift" scenario (valid backends, wrong hash) will be caught by
     * routerRouteDrift only; a "bad-backend" scenario is caught here only.
     *
     * @return list<DriftEntry>
     */
    private function routerBackendDrift(Node $node): array
    {
        if (! $this->nodeRoleAssignments->nodeCanServeRouter($node)) {
            return [];
        }

        $route = ProxyRoute::query()
            ->where('domain', S3RouteRegistrar::ServiceDomain)
            ->first();

        if (! $route instanceof ProxyRoute) {
            // Absent route is covered by router_route_missing — do not emit backend_invalid.
            return [];
        }

        $config = is_array($route->config) ? $route->config : [];
        $upstreams = is_array($config['upstreams'] ?? null) ? $config['upstreams'] : null;

        // Empty upstream list is an invalid pool.
        if ($upstreams === null || $upstreams === []) {
            return [
                new DriftEntry(
                    family: 'proxy',
                    key: self::RouterBackendKey,
                    kind: DriftKind::Divergent,
                    summary: 'S3 service route s3.orbit backend pool is empty.',
                    detail: [
                        'domain' => S3RouteRegistrar::ServiceDomain,
                        'reason' => 'empty_pool',
                    ],
                ),
            ];
        }

        // Validate each upstream — every host must end with .s3.orbit and use port 8333.
        foreach ($upstreams as $upstream) {
            if (! is_array($upstream)) {
                continue;
            }

            $host = is_string($upstream['host'] ?? null) ? $upstream['host'] : '';
            $port = is_int($upstream['port'] ?? null) ? $upstream['port'] : null;

            if (! $this->isValidS3BackendHost($host) || $port !== S3BackendName::BackendPort) {
                return [
                    new DriftEntry(
                        family: 'proxy',
                        key: self::RouterBackendKey,
                        kind: DriftKind::Divergent,
                        summary: "S3 service route s3.orbit backend pool contains invalid upstream host '{$host}'.",
                        detail: [
                            'domain' => S3RouteRegistrar::ServiceDomain,
                            'reason' => 'invalid_upstream_host',
                            'invalid_host' => $host,
                            'port' => $port,
                        ],
                    ),
                ];
            }
        }

        return [];
    }

    /**
     * Returns true when the route's backend pool is semantically invalid
     * (empty upstreams or any upstream is not a valid .s3.orbit:8333 host).
     */
    private function hasInvalidBackendPool(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];
        $upstreams = is_array($config['upstreams'] ?? null) ? $config['upstreams'] : null;

        if ($upstreams === null || $upstreams === []) {
            return true;
        }

        foreach ($upstreams as $upstream) {
            if (! is_array($upstream)) {
                continue;
            }

            $host = is_string($upstream['host'] ?? null) ? $upstream['host'] : '';
            $port = is_int($upstream['port'] ?? null) ? $upstream['port'] : null;

            if (! $this->isValidS3BackendHost($host) || $port !== S3BackendName::BackendPort) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect orphaned s3.orbit service route rows.
     *
     * Fires when the node is a router, the s3.orbit route row exists,
     * but no active s3 role assignment remains in the topology.
     *
     * @return list<DriftEntry>
     */
    private function routerRouteOrphanedDrift(Node $node): array
    {
        if (! $this->nodeRoleAssignments->nodeCanServeRouter($node)) {
            return [];
        }

        if ($this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::S3->value) !== []) {
            return [];
        }

        $route = ProxyRoute::query()
            ->where('domain', S3RouteRegistrar::ServiceDomain)
            ->first();

        if (! $route instanceof ProxyRoute) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'proxy',
                key: self::RouterRouteOrphanedKey,
                kind: DriftKind::Extra,
                summary: 'The s3.orbit service route row exists but no active s3 role assignment remains.',
                detail: [
                    'domain' => S3RouteRegistrar::ServiceDomain,
                ],
            ),
        ];
    }

    /**
     * Check whether public S3 ingress routes exist and match gateway intent for
     * each active seaweedfs tool row that lists public hosts.
     *
     * Gated on: node must be an ingress node.
     * Reports only when intent->node_id === node->id (this is the owning ingress).
     * Drift detail includes seaweedfs_tool_id so restore() can locate the seaweedfs row.
     *
     * @return list<DriftEntry>
     */
    private function publicRouteDrift(Node $node): array
    {
        if (! $this->nodeRoleAssignments->nodeCanServeIngress($node)) {
            return [];
        }

        $drift = [];
        $s3NodeIds = $this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::S3->value);

        if ($s3NodeIds === []) {
            return [];
        }

        /** @var Collection<int, NodeTool> $seaweedfsTools */
        $seaweedfsTools = NodeTool::query()
            ->where('name', 'seaweedfs')
            ->whereIn('node_id', $s3NodeIds)
            ->orderBy('id')
            ->get();

        foreach ($seaweedfsTools as $seaweedfs) {
            try {
                $intents = $this->routeRegistrar->publicRouteIntents($seaweedfs);
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
                        missingSummary: "S3 public route {$intent->domain} is missing from gateway proxy registry.",
                        mismatchSummary: "S3 public route {$intent->domain} differs from gateway S3 route intent.",
                        detail: [
                            'seaweedfs_tool_id' => $seaweedfs->id,
                        ],
                    ),
                ];
            }
        }

        return $drift;
    }

    /**
     * Compare an intent ProxyRoute against the actual row in the registry.
     *
     * Returns Missing when the route is absent, Divergent when any intent
     * field differs (via mismatchReason), or an empty array when everything
     * aligns.
     *
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

    /**
     * A valid S3 backend host is of the form <name>.s3.orbit (where <name> is
     * the node name). It must end with the .s3.orbit suffix and must not be
     * empty or just the suffix itself.
     */
    private function isValidS3BackendHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        if (! str_ends_with($host, '.s3.orbit')) {
            return false;
        }

        // The prefix before ".s3.orbit" must be non-empty.
        $prefix = substr($host, 0, strlen($host) - strlen('.s3.orbit'));

        return $prefix !== '';
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
