<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Runtime\OrbitContainerNames;

final readonly class ProxyRouteProbe
{
    private const array OwnerTypes = ['app', 'app-websocket', 'workspace', 'gateway', 'router', 's3', 'tool', 'custom'];

    private const array Kinds = ['app', 'workspace', 'internal', 'proxy', 'redirect'];

    public function __construct(
        private ?RemoteShell $remoteShell = null,
    ) {}

    public function key(): string
    {
        return 'proxy';
    }

    public function label(): string
    {
        return 'Proxy';
    }

    public function introspect(ProxyRoute $route): ProbeSnapshot
    {
        $route->loadMissing('node');

        if (! $route->node instanceof Node || $route->domain === '') {
            return new ProbeSnapshot([]);
        }

        $public = $this->inspectRouteFile($route->node, $route->domain);

        if (! $this->usesIngressPlacement($route)) {
            return new ProbeSnapshot([
                $route->domain => $public,
            ]);
        }

        $router = [];
        $routerArtifact = $this->routerArtifact($route);
        $routerNodeId = $routerArtifact['node_id'] ?? null;
        $routerNode = is_int($routerNodeId) ? Node::query()->find($routerNodeId) : null;

        if ($routerNode instanceof Node) {
            $router = $this->inspectRouteFile($routerNode, $route->domain);
        }

        $backends = [];

        foreach ($this->backendArtifacts($route) as $artifact) {
            $nodeId = $artifact['node_id'] ?? null;

            if (! is_int($nodeId)) {
                continue;
            }

            $backendNode = Node::query()->find($nodeId);

            if (! $backendNode instanceof Node) {
                continue;
            }

            $backends[$nodeId] = $this->inspectRouteFile($backendNode, $route->domain, backend: true);
        }

        return new ProbeSnapshot([
            $route->domain => [
                ...$public,
                'public' => $public,
                'router' => $router,
                'backends' => $backends,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectRouteFile(Node $node, string $domain, bool $backend = false): array
    {
        $script = <<<'BASH'
set -euo pipefail
domain="$ORBIT_PROXY_DOMAIN"
suffix="${ORBIT_PROXY_SUFFIX:-}"
path="/etc/caddy/sites/${domain}${suffix}.caddy"
exists=0
hash=""
cert=""
key=""
cert_exists=0
key_exists=0

if [ -f "$path" ]; then
    exists=1
    hash=$(sha256sum "$path" | awk '{print $1}')
    cert=$(awk '$1 == "tls" && $2 != "internal" {print $2; exit}' "$path")
    key=$(awk '$1 == "tls" && $2 != "internal" {print $3; exit}' "$path")
    [ -n "$cert" ] && [ -f "$cert" ] && cert_exists=1
    [ -n "$key" ] && [ -f "$key" ] && key_exists=1
fi

printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$exists" "$hash" "$cert" "$key" "$cert_exists" "$key_exists"
BASH;

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($node, $script, [
            'throw' => true,
            'metadata' => [
                'ORBIT_PROXY_DOMAIN' => $domain,
                'ORBIT_PROXY_SUFFIX' => $backend ? '.backend' : '',
            ],
        ]);

        $parts = explode("\t", trim($result->stdout), 6);

        if (count($parts) !== 6) {
            return [];
        }

        [$exists, $hash, $cert, $key, $certExists, $keyExists] = $parts;

        return [
            'route_exists' => $exists === '1',
            'route_hash' => $hash,
            'cert_path' => $cert,
            'key_path' => $key,
            'cert_exists' => $certExists === '1',
            'key_exists' => $keyExists === '1',
        ];
    }

    public function introspectNode(Node $node): ProbeSnapshot
    {
        $script = <<<'BASH'
set -euo pipefail
if [ ! -d /etc/caddy/sites ]; then
    exit 0
fi
for f in /etc/caddy/sites/*.caddy; do
    [ -e "$f" ] || continue
    name=$(basename "$f" .caddy)
    hash=$(sha256sum "$f" | awk '{print $1}')
    cert=$(awk '$1 == "tls" && $2 != "internal" {print $2; exit}' "$f")
    key=$(awk '$1 == "tls" && $2 != "internal" {print $3; exit}' "$f")
    cert_exists=0; key_exists=0
    [ -n "$cert" ] && [ -f "$cert" ] && cert_exists=1
    [ -n "$key" ] && [ -f "$key" ] && key_exists=1
    printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$name" "$hash" "$cert" "$key" "$cert_exists" "$key_exists"
done
BASH;

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($node, $script, ['throw' => true]);
        $items = [];

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 6);

            if (count($parts) !== 6) {
                continue;
            }

            [$name, $hash, $cert, $key, $certExists, $keyExists] = $parts;
            $items[$name] = [
                'route_exists' => true,
                'route_hash' => $hash,
                'cert_path' => $cert,
                'key_path' => $key,
                'cert_exists' => $certExists === '1',
                'key_exists' => $keyExists === '1',
            ];
        }

        return new ProbeSnapshot($items);
    }

    /**
     * Probe the orbit-caddy container on a serving node. Returns a single
     * snapshot keyed by the canonical container name with `runtime_status`
     * (one of `available`, `no_docker`, `daemon_unavailable`),
     * `container_exists`, and `container_running` flags. Host caddy.service is
     * intentionally never inspected — orbit-caddy is the steady-state runtime.
     *
     * The script tag `# orbit-proxy-doctor:caddy-container-probe` lets test
     * fakes and other tooling identify this probe unambiguously even when
     * other code paths invoke `docker container inspect orbit-caddy` (e.g.
     * CaddyTool::updateScript).
     */
    public function introspectCaddyContainer(Node $node): ProbeSnapshot
    {
        $caddyName = (new OrbitContainerNames)->caddy();

        $script = sprintf(
            <<<'BASH'
# orbit-proxy-doctor:caddy-container-probe
container=%s
runtime="available"
exists="false"
running="false"

if ! command -v docker >/dev/null 2>&1; then
    runtime="no_docker"
elif ! docker info >/dev/null 2>&1; then
    runtime="daemon_unavailable"
else
    if docker container inspect --format '{{.State.Running}}' "$container" >/dev/null 2>&1; then
        exists="true"
        state=$(docker container inspect --format '{{.State.Running}}' "$container" 2>/dev/null || echo "false")
        if [ "$state" = "true" ]; then
            running="true"
        fi
    fi
fi
printf '%%s\t%%s\t%%s\n' "$runtime" "$exists" "$running"
BASH,
            escapeshellarg($caddyName),
        );

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($node, $script, ['throw' => false]);
        $parts = explode("\t", trim($result->stdout), 3);
        $runtimeStatus = ($parts[0] ?? '') !== '' ? $parts[0] : 'unknown';

        return new ProbeSnapshot([
            $caddyName => [
                'runtime_status' => $runtimeStatus,
                'container_exists' => ($parts[1] ?? '') === 'true',
                'container_running' => ($parts[2] ?? '') === 'true',
            ],
        ]);
    }

    /**
     * Compare the observed orbit-caddy container state on a serving node
     * against the expectation that it must exist and be running for mounted
     * proxy route artifacts to take effect.
     *
     * @return list<DriftEntry>
     */
    public function diffCaddyContainer(Node $node, ProbeSnapshot $snapshot): array
    {
        $caddyName = (new OrbitContainerNames)->caddy();
        $observed = $snapshot->get($caddyName);

        if (! is_array($observed)) {
            return [];
        }

        $runtimeStatus = is_string($observed['runtime_status'] ?? null)
            ? $observed['runtime_status']
            : 'available';

        if ($runtimeStatus !== 'available') {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.docker_runtime_unavailable',
                    kind: DriftKind::Divergent,
                    summary: $runtimeStatus === 'no_docker'
                        ? "Docker CLI is not installed on {$node->name}; orbit-caddy cannot be probed."
                        : "Docker daemon is unreachable on {$node->name}; orbit-caddy cannot be probed.",
                    detail: [
                        'container' => $caddyName,
                        'node' => $node->name,
                        'runtime_status' => $runtimeStatus,
                    ],
                ),
            ];
        }

        if (($observed['container_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.caddy_container_missing',
                    kind: DriftKind::Missing,
                    summary: "Proxy runtime container {$caddyName} is missing on {$node->name}.",
                    detail: [
                        'container' => $caddyName,
                        'node' => $node->name,
                    ],
                ),
            ];
        }

        if (($observed['container_running'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.caddy_container_down',
                    kind: DriftKind::Divergent,
                    summary: "Proxy runtime container {$caddyName} is not running on {$node->name}.",
                    detail: [
                        'container' => $caddyName,
                        'node' => $node->name,
                    ],
                ),
            ];
        }

        return [];
    }

    public function snapshotForAdopt(Node $node): ProbeSnapshot
    {
        $script = <<<'BASH'
set -euo pipefail
if [ ! -d /etc/caddy/sites ]; then
    exit 0
fi
for f in /etc/caddy/sites/*.caddy; do
    [ -e "$f" ] || continue
    name=$(basename "$f" .caddy)
    vhost_hash=$(sha256sum "$f" | awk '{print $1}')
    body_b64=$(base64 -w0 "$f" 2>/dev/null || base64 "$f" | tr -d '\n')
    printf '%s\t%s\t%s\n' "$name" "$vhost_hash" "$body_b64"
done
BASH;

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($node, $script, ['throw' => true]);
        $items = [];

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 3);

            if (count($parts) !== 3) {
                continue;
            }

            [$name, $hash, $bodyB64] = $parts;
            $body = base64_decode($bodyB64, true);

            if ($body === false) {
                continue;
            }

            $items[$name] = [
                'hash' => $hash,
                'body' => $body,
            ];
        }

        return new ProbeSnapshot($items);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(ProxyRoute $route, ProbeSnapshot $snapshot): array
    {
        return [
            ...$this->checkRecordCompleteness($route),
            ...$this->checkOwnerEligibility($route),
            ...$this->checkNodeEligibility($route),
            ...$this->checkCustomDomainConflict($route),
            ...$this->checkBackendReality($route, $snapshot),
            ...$this->checkTlsReality($route, $snapshot),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    public function diffNode(Node $node, ProbeSnapshot $snapshot): array
    {
        $drift = [];
        $allRoutes = ProxyRoute::query()->get();
        $dbRoutes = $allRoutes
            ->filter(fn (ProxyRoute $route): bool => $route->node_id === $node->id)
            ->values();
        $observedDomains = $snapshot->keys();
        $expectedDomains = $this->expectedRouteDomainsForNode($allRoutes->all(), $node);

        foreach ($dbRoutes as $route) {
            $routeDrift = $this->diff($route, $snapshot);

            if (! in_array($route->domain, $observedDomains, true)) {
                $hasBackendDrift = collect($routeDrift)->contains(
                    fn (DriftEntry $entry): bool => in_array($entry->key, [
                        'proxy.route_missing',
                        'proxy.route_mismatch',
                        'proxy.public_route_missing',
                        'proxy.public_route_mismatch',
                    ], true)
                );

                if (! $hasBackendDrift) {
                    $routeDrift[] = new DriftEntry(
                        family: $this->key(),
                        key: 'proxy.route_missing',
                        kind: DriftKind::Missing,
                        summary: "Proxy backend route {$route->domain} is missing on the serving node.",
                    );
                }
            }

            $drift = array_merge($drift, $routeDrift);
        }

        foreach ($snapshot->keys() as $domain) {
            $domain = (string) $domain;

            if (in_array($domain, $expectedDomains, true)) {
                continue;
            }

            $drift[] = new DriftEntry(
                family: $this->key(),
                key: $domain,
                kind: DriftKind::Extra,
                summary: "Proxy route '{$domain}' exists on node but not in gateway registry.",
            );
        }

        return $drift;
    }

    /**
     * @return list<string>
     */
    public function expectedDomainsForNode(Node $node): array
    {
        return $this->expectedRouteDomainsForNode(ProxyRoute::query()->get()->all(), $node);
    }

    /**
     * @param  list<ProxyRoute>  $routes
     * @return list<string>
     */
    private function expectedRouteDomainsForNode(array $routes, Node $node): array
    {
        $domains = [];

        foreach ($routes as $route) {
            if ($route->node_id === $node->id) {
                $domains[] = $route->domain;
            }

            $routerNodeId = $this->routerArtifact($route)['node_id'] ?? null;

            if ($routerNodeId === $node->id) {
                $domains[] = $route->domain;
            }

            foreach ($this->backendArtifacts($route) as $artifact) {
                if (($artifact['node_id'] ?? null) === $node->id) {
                    $domains[] = "{$route->domain}.backend";
                }
            }
        }

        return array_values(array_unique(array_filter($domains, is_string(...))));
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(ProxyRoute $route): array
    {
        if (
            ! is_string($route->domain)
            || $route->domain === ''
            || ! is_int($route->node_id)
            || ! in_array($route->owner_type, self::OwnerTypes, true)
            || ! in_array($route->kind, self::Kinds, true)
            || ! is_string($route->source_hash)
            || $route->source_hash === ''
            || ! $this->hasTargetShape($route)
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Proxy route record for {$route->domain} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkOwnerEligibility(ProxyRoute $route): array
    {
        $route->loadMissing(['app', 'workspace']);

        if ($route->owner_type === 'app' && ! $route->app instanceof App) {
            return [$this->ownerInvalid($route, 'app')];
        }

        if ($route->owner_type === 'app-websocket' && ! $route->app instanceof App) {
            return [$this->ownerInvalid($route, 'app-websocket')];
        }

        if ($route->owner_type === 'workspace' && ! $route->workspace instanceof Workspace) {
            return [$this->ownerInvalid($route, 'workspace')];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkNodeEligibility(ProxyRoute $route): array
    {
        $route->loadMissing('node');

        if (! $route->node instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Proxy route {$route->domain} points at a missing serving node.",
                ),
            ];
        }

        if (! $route->node->isActive() || ! $this->canServeProxyRoutes($route, $route->node)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Proxy route {$route->domain} is served by node {$route->node->name}, which is not an eligible active serving node.",
                    detail: [
                        'node' => $route->node->name,
                        'role' => $route->node->displayRole(),
                        'status' => $route->node->status,
                    ],
                ),
            ];
        }

        return [];
    }

    private function canServeProxyRoutes(ProxyRoute $route, Node $node): bool
    {
        // Ingress-placed routes (public S3 host routes, public WebSocket routes, etc.)
        // are served on the ingress node — checked first before any owner_type branch.
        if ($this->usesIngressPlacement($route)) {
            return app(NodeRoleAssignments::class)->nodeCanServeIngress($node);
        }

        // Router-owned service routes (websocket.orbit, s3.orbit) live on the router node.
        // Without this branch they would fall through to nodeCanServeGatewayOrAppHostWorkloads
        // and produce a false proxy.node_invalid on a healthy router-only node.
        if ($route->owner_type === 'router') {
            return app(NodeRoleAssignments::class)->nodeCanServeRouter($node);
        }

        $assignments = app(NodeRoleAssignments::class);

        if ($route->owner_type === 'custom') {
            return $assignments->nodeCanServeGatewayOrAppHostWorkloads($node)
                || $assignments->nodeCanServeIngress($node);
        }

        return $assignments->nodeCanServeGatewayOrAppHostWorkloads($node);
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCustomDomainConflict(ProxyRoute $route): array
    {
        if ($route->owner_type !== 'custom') {
            return [];
        }

        $app = App::query()
            ->where('domain', $route->domain)
            ->first();

        if ($app instanceof App) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.domain_conflict',
                    kind: DriftKind::Divergent,
                    summary: "Custom proxy route {$route->domain} conflicts with app {$app->name}.",
                    detail: [
                        'domain' => $route->domain,
                        'owner_type' => 'app',
                        'owner_name' => $app->name,
                    ],
                ),
            ];
        }

        return [];
    }

    private function ownerInvalid(ProxyRoute $route, string $ownerType): DriftEntry
    {
        return new DriftEntry(
            family: $this->key(),
            key: 'proxy.owner_invalid',
            kind: DriftKind::Divergent,
            summary: "Proxy route {$route->domain} points at a missing {$ownerType} owner.",
            detail: [
                'owner_type' => $ownerType,
            ],
        );
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkBackendReality(ProxyRoute $route, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($route->domain);

        if ($observed === null) {
            return $this->checkBackendArtifactNodes($route);
        }

        if ($this->usesIngressPlacement($route)) {
            return [
                ...$this->checkPublicRouteReality($route, $observed),
                ...$this->checkRouterArtifactNode($route),
                ...$this->checkRouterRouteReality($route, $observed),
                ...$this->checkBackendArtifactNodes($route),
                ...$this->checkBackendArtifactReality($route, $observed),
            ];
        }

        if (($observed['route_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.route_missing',
                    kind: DriftKind::Missing,
                    summary: "Proxy backend route {$route->domain} is missing on the serving node.",
                ),
            ];
        }

        if (($observed['route_hash'] ?? null) !== $route->source_hash) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.route_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Proxy backend route {$route->domain} differs from gateway proxy intent.",
                    detail: [
                        'expected_hash' => $route->source_hash,
                        'observed_hash' => $observed['route_hash'] ?? null,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $observed
     * @return list<DriftEntry>
     */
    private function checkPublicRouteReality(ProxyRoute $route, array $observed): array
    {
        $public = $this->publicObservation($observed);

        if (($public['route_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.public_route_missing',
                    kind: DriftKind::Missing,
                    summary: "Public proxy route {$route->domain} is missing on the ingress node.",
                ),
            ];
        }

        if (($public['route_hash'] ?? null) !== $route->source_hash) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.public_route_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Public proxy route {$route->domain} differs from gateway proxy intent.",
                    detail: [
                        'expected_hash' => $route->source_hash,
                        'observed_hash' => $public['route_hash'] ?? null,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRouterArtifactNode(ProxyRoute $route): array
    {
        $artifact = $this->routerArtifact($route);
        $nodeId = $artifact['node_id'] ?? null;
        $node = is_int($nodeId) ? Node::query()->find($nodeId) : null;

        if ($node instanceof Node && app(NodeRoleAssignments::class)->nodeCanServeRouter($node)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'proxy.router_node_invalid',
                kind: DriftKind::Divergent,
                summary: "Router route {$route->domain} points at an invalid router node.",
                detail: [
                    'router_node_id' => $nodeId,
                ],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $observed
     * @return list<DriftEntry>
     */
    private function checkRouterRouteReality(ProxyRoute $route, array $observed): array
    {
        $router = $this->routerObservation($observed);

        if (($router['route_exists'] ?? false) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.router_route_missing',
                    kind: DriftKind::Missing,
                    summary: "Private router route {$route->domain} is missing on the router node.",
                ),
            ];
        }

        $expectedHash = $this->routerArtifact($route)['source_hash'] ?? null;

        if (($router['route_hash'] ?? null) !== $expectedHash) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.router_route_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Private router route {$route->domain} differs from gateway proxy intent.",
                    detail: [
                        'expected_hash' => $expectedHash,
                        'observed_hash' => $router['route_hash'] ?? null,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkBackendArtifactNodes(ProxyRoute $route): array
    {
        $drift = [];

        foreach ($this->backendArtifacts($route) as $artifact) {
            $nodeId = $artifact['node_id'] ?? null;
            $node = is_int($nodeId) ? Node::query()->find($nodeId) : null;

            if ($node instanceof Node && $node->isActive() && app(NodeRoleAssignments::class)->nodeHasActiveRole($node, 'app-prod')) {
                continue;
            }

            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'proxy.backend_node_invalid',
                kind: DriftKind::Divergent,
                summary: "Private backend route {$route->domain} points at an invalid backend node.",
                detail: [
                    'backend_node_id' => $nodeId,
                ],
            );
        }

        return $drift;
    }

    /**
     * @param  array<string, mixed>  $observed
     * @return list<DriftEntry>
     */
    private function checkBackendArtifactReality(ProxyRoute $route, array $observed): array
    {
        $drift = [];
        $backends = is_array($observed['backends'] ?? null) ? $observed['backends'] : [];

        foreach ($this->backendArtifacts($route) as $artifact) {
            $nodeId = $artifact['node_id'] ?? null;

            if (! is_int($nodeId)) {
                continue;
            }

            $backend = $backends[$nodeId] ?? null;

            if (! is_array($backend)) {
                continue;
            }

            if (($backend['route_exists'] ?? null) === false) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.backend_route_missing',
                    kind: DriftKind::Missing,
                    summary: "Private backend route {$route->domain} is missing on backend node.",
                    detail: [
                        'backend_node_id' => $nodeId,
                    ],
                );

                continue;
            }

            if (($backend['route_hash'] ?? null) !== ($artifact['source_hash'] ?? null)) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.backend_route_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Private backend route {$route->domain} differs from gateway proxy intent.",
                    detail: [
                        'backend_node_id' => $nodeId,
                        'expected_hash' => $artifact['source_hash'] ?? null,
                        'observed_hash' => $backend['route_hash'] ?? null,
                    ],
                );
            }
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkTlsReality(ProxyRoute $route, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($route->domain);

        if ($observed === null || ! $this->expectsOrbitTls($route)) {
            return [];
        }

        $observed = $this->usesIngressPlacement($route) ? $this->publicObservation($observed) : $observed;

        if (($observed['route_exists'] ?? null) === false) {
            return [];
        }

        if (($observed['cert_exists'] ?? null) === false || ($observed['key_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.tls_missing',
                    kind: DriftKind::Missing,
                    summary: "Proxy route {$route->domain} is missing Orbit-managed TLS material.",
                ),
            ];
        }

        $expected = $this->expectedTlsPaths($route);

        if (($observed['cert_path'] ?? null) !== $expected['cert'] || ($observed['key_path'] ?? null) !== $expected['key']) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.tls_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Proxy route {$route->domain} TLS material does not match gateway proxy intent.",
                    detail: [
                        'expected' => $expected,
                        'observed' => [
                            'cert' => $observed['cert_path'] ?? null,
                            'key' => $observed['key_path'] ?? null,
                        ],
                    ],
                ),
            ];
        }

        return [];
    }

    private function expectsOrbitTls(ProxyRoute $route): bool
    {
        if ($this->usesIngressPlacement($route)) {
            return false;
        }

        $config = is_array($route->config) ? $route->config : [];
        $tls = $config['tls'] ?? null;

        if ($tls === 'internal') {
            return false;
        }

        $managedBy = is_array($tls)
            ? ($tls['managed_by'] ?? $config['tls_managed_by'] ?? 'orbit')
            : ($config['tls_managed_by'] ?? 'orbit');

        if ($managedBy === 'acme') {
            return false;
        }

        return $managedBy === 'orbit';
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function expectedTlsPaths(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $cert = $config['tls']['cert_path'] ?? null;
        $key = $config['tls']['key_path'] ?? null;

        return [
            'cert' => is_string($cert) && $cert !== '' ? $cert : "/etc/orbit/certs/{$route->domain}.crt",
            'key' => is_string($key) && $key !== '' ? $key : "/etc/orbit/certs/{$route->domain}.key",
        ];
    }

    private function hasTargetShape(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];

        if ($route->kind === 'redirect') {
            $target = $config['target']['value'] ?? $config['redirect'] ?? $config['redirect_url'] ?? null;
            $code = $config['code'] ?? $config['redirect_code'] ?? null;

            return is_string($target)
                && $target !== ''
                && is_int($code);
        }

        if ($route->kind === 'proxy') {
            // Router-owned service routes that carry a backend pool (e.g. websocket.orbit)
            // are validated by pool presence, not by a target value.
            if ($route->owner_type === 'router' && isset($config['router_backend_pool'])) {
                return $this->hasRouterBackendPool($config);
            }

            $target = $config['target']['value'] ?? $config['upstream'] ?? $config['target'] ?? null;

            return is_string($target) && $target !== '';
        }

        if ($route->kind === 'app') {
            return is_int($route->app_id);
        }

        if ($route->kind === 'workspace') {
            return is_int($route->workspace_id);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function hasRouterBackendPool(array $config): bool
    {
        $pool = $config['router_backend_pool'] ?? null;

        if (! is_array($pool) || $pool === []) {
            return false;
        }

        return array_all(
            $pool,
            fn (mixed $backend): bool => is_array($backend)
                && is_string($backend['url'] ?? null)
                && $backend['url'] !== '',
        );
    }

    private function usesIngressPlacement(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];

        return ($config['placement'] ?? null) === 'ingress';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function backendArtifacts(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $artifacts = $config['backend_artifacts'] ?? null;

        if (! is_array($artifacts)) {
            return [];
        }

        return array_values(array_filter($artifacts, is_array(...)));
    }

    /**
     * @return array<string, mixed>
     */
    private function routerArtifact(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $artifact = $config['router_artifact'] ?? null;

        return is_array($artifact) ? $artifact : [];
    }

    /**
     * @param  array<string, mixed>  $observed
     * @return array<string, mixed>
     */
    private function publicObservation(array $observed): array
    {
        $public = $observed['public'] ?? null;

        return is_array($public) ? $public : $observed;
    }

    /**
     * @param  array<string, mixed>  $observed
     * @return array<string, mixed>
     */
    private function routerObservation(array $observed): array
    {
        $router = $observed['router'] ?? null;

        return is_array($router) ? $router : [];
    }
}
