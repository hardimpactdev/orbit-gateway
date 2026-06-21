<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\ProxyRouteRenderer;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

final readonly class S3RouteRegistrar
{
    public const string ServiceDomain = 's3.orbit';

    public const string ServiceEndpoint = 'https://s3.orbit';

    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private ProxyRouteRenderer $proxyRouteRenderer,
    ) {}

    /**
     * Register or update the router-owned private s3.orbit service route.
     *
     * Resolves the active router node and all active seaweedfs tool rows to build
     * the S3 backend pool. The pool stores concrete SeaweedFS backend URLs in the
     * form http://<node>.s3.orbit:8333 (WireGuard:8333).
     */
    public function syncServiceRoute(): ProxyRoute
    {
        $intent = $this->serviceRouteIntent();

        $route = ProxyRoute::query()->updateOrCreate(
            ['domain' => self::ServiceDomain],
            [
                'node_id' => $intent->node_id,
                'app_id' => $intent->app_id,
                'workspace_id' => $intent->workspace_id,
                'owner_type' => $intent->owner_type,
                'kind' => $intent->kind,
                'config' => $intent->config,
                'source_hash' => $intent->source_hash,
            ],
        );

        return $route->refresh();
    }

    /**
     * Build the expected s3.orbit ProxyRoute without persisting it.
     *
     * Returns an unsaved ProxyRoute model carrying the canonical field values
     * (node_id, domain, owner_type, kind, config, source_hash, app_id=null,
     * workspace_id=null) that the S3 proxy doctor probe uses for intent
     * comparison.
     */
    public function serviceRouteIntent(): ProxyRoute
    {
        $router = $this->routerNode();
        $seaweedfsTools = $this->activeSeaweedfsTools();
        $config = $this->serviceRouteConfig($seaweedfsTools);

        $sourceHash = $this->proxyRouteRenderer->sourceHash(new ProxyRoute([
            'node_id' => $router->id,
            'domain' => self::ServiceDomain,
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => $config,
        ]));

        return new ProxyRoute([
            'node_id' => $router->id,
            'domain' => self::ServiceDomain,
            'app_id' => null,
            'workspace_id' => null,
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => $config,
            'source_hash' => $sourceHash,
        ]);
    }

    /**
     * Register or update ingress routes for all public hosts listed on the
     * given seaweedfs tool row. Each public host gets a separate ingress route
     * on the ingress node that forwards to router, which relays S3 traffic to
     * the stable https://s3.orbit service endpoint.
     */
    public function syncPublicHosts(NodeTool $seaweedfs): void
    {
        $publicHosts = $this->readPublicHosts($seaweedfs);

        if ($publicHosts === []) {
            return;
        }

        $ingress = $this->ingressNode();
        $router = $this->routerNode();

        foreach ($publicHosts as $host) {
            $this->syncPublicHost($ingress, $router, $seaweedfs, $host);
        }
    }

    /**
     * Build the expected ingress ProxyRoute models for all public hosts listed
     * on the given seaweedfs tool row, without persisting them.
     *
     * Returns an unsaved ProxyRoute per host, carrying the canonical field
     * values (node_id, domain, owner_type, kind, config, source_hash,
     * app_id=null, workspace_id=null) that the S3 proxy doctor probe uses for
     * intent comparison.
     *
     * @return list<ProxyRoute>
     */
    public function publicRouteIntents(NodeTool $seaweedfs): array
    {
        $publicHosts = $this->readPublicHosts($seaweedfs);

        if ($publicHosts === []) {
            return [];
        }

        $ingress = $this->ingressNode();
        $router = $this->routerNode();

        return array_map(
            fn (string $host): ProxyRoute => $this->publicRouteIntent($ingress, $router, $host),
            $publicHosts,
        );
    }

    /**
     * Build the expected ingress ProxyRoute model for a single public S3 host
     * without persisting it.
     */
    public function publicRouteIntent(Node $ingress, Node $router, string $host): ProxyRoute
    {
        $config = $this->publicRouteConfig($router, $host);

        $sourceHash = $this->proxyRouteRenderer->sourceHash(new ProxyRoute([
            'node_id' => $ingress->id,
            'domain' => $host,
            'owner_type' => 's3',
            'kind' => 'proxy',
            'config' => $config,
        ]));

        return new ProxyRoute([
            'node_id' => $ingress->id,
            'domain' => $host,
            'app_id' => null,
            'workspace_id' => null,
            'owner_type' => 's3',
            'kind' => 'proxy',
            'config' => $config,
            'source_hash' => $sourceHash,
        ]);
    }

    /**
     * Remove the ingress route for a single public host when it is owned by
     * the seaweedfs tool. Ownership is confirmed by owner_type, owner_name, and
     * the protocol discriminator so unrelated tool routes are never removed.
     */
    public function removePublicHost(NodeTool $seaweedfs, string $host): void
    {
        ProxyRoute::query()
            ->where('domain', $host)
            ->where('owner_type', 's3')
            ->whereJsonContains('config->owner_name', 'seaweedfs')
            ->whereJsonContains('config->protocol', 's3')
            ->delete();
    }

    private function routerNode(): Node
    {
        $router = $this->nodeRoleAssignments->activeRouterNodeQuery()
            ->orderBy('id')
            ->first();

        if (! $router instanceof Node) {
            throw new RuntimeException('The S3 service route requires an active router node.');
        }

        return $router;
    }

    private function ingressNode(): Node
    {
        $ingress = $this->nodeRoleAssignments->activeIngressNodeQuery()
            ->orderBy('id')
            ->first();

        if (! $ingress instanceof Node) {
            throw new RuntimeException('The S3 public host route requires an active ingress node.');
        }

        return $ingress;
    }

    /**
     * @return Collection<int, NodeTool>
     */
    private function activeSeaweedfsTools(): Collection
    {
        $s3NodeIds = $this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::S3->value);

        if ($s3NodeIds === []) {
            throw new RuntimeException('The S3 service route requires at least one active s3 backend.');
        }

        /** @var Collection<int, NodeTool> */
        $tools = NodeTool::query()
            ->where('name', 'seaweedfs')
            ->whereIn('node_id', $s3NodeIds)
            ->orderBy('id')
            ->get();

        if ($tools->isEmpty()) {
            throw new RuntimeException('The S3 service route requires at least one active seaweedfs tool row.');
        }

        return $tools;
    }

    /**
     * Build the service route config from the active seaweedfs tool rows.
     *
     * The pool stores concrete SeaweedFS backend URLs using the backend_host from
     * the tool config (e.g. storage-1.s3.orbit) on WireGuard port 8333.
     *
     * V1 supports one backend; the pool shape is stored so multi-backend
     * support can be added without a schema change.
     *
     * @param  Collection<int, NodeTool>  $seaweedfsTools
     * @return array<string, mixed>
     */
    private function serviceRouteConfig(Collection $seaweedfsTools): array
    {
        $upstreams = $seaweedfsTools
            ->map(function (NodeTool $tool): array {
                $backendHost = $this->backendHost($tool);

                return [
                    'scheme' => S3BackendName::BackendScheme,
                    'host' => $backendHost,
                    'port' => S3BackendName::BackendPort,
                ];
            })
            ->values()
            ->all();

        $firstUpstream = $upstreams[0];
        $targetUrl = "{$firstUpstream['scheme']}://{$firstUpstream['host']}:{$firstUpstream['port']}";

        return [
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => [
                'type' => 'upstream',
                'value' => $targetUrl,
            ],
            'upstreams' => $upstreams,
        ];
    }

    private function backendHost(NodeTool $tool): string
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $backendHost = $config['backend_host'] ?? null;

        if (! is_string($backendHost) || $backendHost === '') {
            throw new RuntimeException("The seaweedfs tool row (id={$tool->id}) is missing a backend_host in config.");
        }

        return $backendHost;
    }

    private function syncPublicHost(Node $ingress, Node $router, NodeTool $seaweedfs, string $host): void
    {
        $intent = $this->publicRouteIntent($ingress, $router, $host);

        ProxyRoute::query()->updateOrCreate(
            ['domain' => $host],
            [
                'node_id' => $intent->node_id,
                'app_id' => $intent->app_id,
                'workspace_id' => $intent->workspace_id,
                'owner_type' => $intent->owner_type,
                'kind' => $intent->kind,
                'config' => $intent->config,
                'source_hash' => $intent->source_hash,
            ],
        );
    }

    /**
     * Build the ingress route config for a single public S3 host.
     *
     * The route sits on the ingress node and uses placement=ingress so the
     * proxy renderer emits a Caddy site that reverse-proxies to the router
     * node, preserving the request Host header and X-Forwarded-Proto. The
     * router then relays the traffic onward to the stable s3.orbit endpoint.
     *
     * @return array<string, mixed>
     */
    private function publicRouteConfig(Node $router, string $host): array
    {
        $routerAddress = is_string($router->wireguard_address) ? trim($router->wireguard_address) : '';

        if ($routerAddress === '') {
            throw new RuntimeException("Router node '{$router->name}' requires a WireGuard address for S3 public host ingress.");
        }

        return [
            'placement' => 'ingress',
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => [
                'type' => 'upstream',
                'value' => self::ServiceEndpoint,
            ],
            'router_upstream' => [
                'node_id' => $router->id,
                'node' => $router->name,
                'url' => "http://{$routerAddress}:80",
            ],
            'tls' => [
                'cert_path' => "/etc/orbit/certs/{$host}.crt",
                'key_path' => "/etc/orbit/certs/{$host}.key",
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function readPublicHosts(NodeTool $seaweedfs): array
    {
        $config = is_array($seaweedfs->config) ? $seaweedfs->config : [];
        $hosts = $config['public_hosts'] ?? [];

        if (! is_array($hosts)) {
            return [];
        }

        /** @var list<string> */
        return array_values(array_filter($hosts, is_string(...)));
    }
}
