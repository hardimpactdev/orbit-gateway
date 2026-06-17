<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Enums\Nodes\NodeStatus;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;

class ProxyRouteIntent
{
    public function __construct(
        private readonly ProxyRouteQuery $query,
        private readonly ProxyRouteRenderer $renderer,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function add(string $domain, string $nodeName, ?string $upstream, ?string $redirect, ?int $code, bool $force, ?Node $caller = null): array
    {
        $node = $this->resolveServingNode($nodeName, $caller, 'proxy:add');
        $this->validateAddTarget($upstream, $redirect, $code);

        $existing = ProxyRoute::query()
            ->with(['node', 'app', 'workspace'])
            ->where('domain', $domain)
            ->first();

        if ($existing instanceof ProxyRoute && $existing->owner_type !== 'custom') {
            throw new GatewayApiException("Domain '{$domain}' is owned by {$existing->owner_type}.", 'proxy.domain_conflict', [
                'domain' => $domain,
                'owner_type' => $existing->owner_type,
            ]);
        }

        $kind = $redirect !== null ? 'redirect' : 'proxy';
        $config = $redirect !== null
            ? ['target' => ['type' => 'redirect', 'value' => $redirect], 'code' => $code ?? 302]
            : ['target' => ['type' => 'upstream', 'value' => $upstream], 'upstream' => $upstream];

        if ($existing instanceof ProxyRoute && ! $this->sameCustomIntent($existing, $node, $kind, $config) && ! $force) {
            throw new GatewayApiException('Existing custom proxy route differs from requested intent. Use --force to replace it.', 'proxy.replacement_consent_required', [
                'domain' => $domain,
            ]);
        }

        $action = $existing instanceof ProxyRoute
            ? ($this->sameCustomIntent($existing, $node, $kind, $config) ? 'converged' : 'updated')
            : 'created';

        $route = ProxyRoute::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'node_id' => $node->id,
                'app_id' => null,
                'workspace_id' => null,
                'owner_type' => 'custom',
                'kind' => $kind,
                'config' => $config,
                'source_hash' => $this->sourceHash($domain, $node->id, $kind, $config),
            ],
        );

        return [
            'data' => [
                'route' => $this->query->toRouteEntity($route->refresh(), 'expected'),
            ],
            'meta' => [
                'action' => $action,
                'warnings' => [$this->runtimeWarning($node->name)],
            ],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function remove(string $domain, ?Node $caller = null): array
    {
        $route = ProxyRoute::query()
            ->with(['node', 'app', 'workspace'])
            ->where('domain', $domain)
            ->first();

        if (! $route instanceof ProxyRoute) {
            throw new GatewayApiException("Proxy route '{$domain}' not found.", 'proxy.not_found', [
                'domain' => $domain,
            ]);
        }

        if ($route->owner_type !== 'custom') {
            throw new GatewayApiException("Domain '{$domain}' is owned by {$route->owner_type}.", 'proxy.owned_route_denied', [
                'domain' => $domain,
                'owner_type' => $route->owner_type,
            ]);
        }

        $node = $route->node;
        $this->authorizeServingNode($node, $caller, 'proxy:remove');

        $entity = $this->query->toRouteEntity($route, 'removed_with_drift');
        $route->delete();

        return [
            'data' => [
                'route' => $entity,
            ],
            'meta' => [
                'backend_removed' => false,
                'tls_removed' => false,
                'warnings' => [$this->cleanupWarning($node->name)],
            ],
        ];
    }

    private function resolveServingNode(string $nodeName, ?Node $caller, string $permission): Node
    {
        $node = Node::query()
            ->where('name', $nodeName)
            ->where('status', NodeStatus::Active->value)
            ->first();

        if (! $node instanceof Node || ! $this->canServeProxyRoutes($node)) {
            throw new GatewayApiException("Unknown node: '{$nodeName}'.", 'validation_failed', [
                'field' => 'node',
                'value' => $nodeName,
            ]);
        }

        $this->authorizeServingNode($node, $caller, $permission);

        return $node;
    }

    private function canServeProxyRoutes(Node $node): bool
    {
        return app(NodeRoleAssignments::class)->nodeCanServeGatewayOrAppHostWorkloads($node);
    }

    private function authorizeServingNode(Node $node, ?Node $caller, string $permission): void
    {
        if (! $caller instanceof Node || app(NodeRoleAssignments::class)->nodeIsGateway($caller)) {
            return;
        }

        $result = app(NodeAccessAuthorizer::class)->authorize($caller, $node, $permission);

        if ($result->allowed) {
            return;
        }

        throw new GatewayApiException('This node is not authorized to manage custom proxy routes for the selected serving node.', 'authorization_failed', [
            'node' => $node->name,
            'reason' => $result->reason,
            'missing_permission' => $result->missingPermission,
            'serving_node' => $node->name,
        ]);
    }

    private function validateAddTarget(?string $upstream, ?string $redirect, ?int $code): void
    {
        if (($upstream === null && $redirect === null) || ($upstream !== null && $redirect !== null)) {
            throw new GatewayApiException('Select exactly one of --upstream or --redirect.', 'validation_failed', [
                'fields' => ['upstream', 'redirect'],
            ]);
        }

        if ($upstream !== null && $code !== null) {
            throw new GatewayApiException('--code may only be used with --redirect.', 'validation_failed', [
                'field' => 'code',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sameCustomIntent(ProxyRoute $route, Node $node, string $kind, array $config): bool
    {
        return $route->node_id === $node->id
            && $route->owner_type === 'custom'
            && $route->kind === $kind
            && $route->config === $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sourceHash(string $domain, int $nodeId, string $kind, array $config): string
    {
        return $this->renderer->sourceHash(new ProxyRoute([
            'node_id' => $nodeId,
            'domain' => $domain,
            'kind' => $kind,
            'owner_type' => 'custom',
            'config' => $config,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeWarning(string $node): array
    {
        return [
            'code' => 'proxy.enactment_deferred',
            'family' => 'proxy',
            'message' => 'Proxy route intent was saved, but backend/TLS enactment is deferred to proxy doctor fix mode.',
            'next_command' => "doctor --family=proxy --restore --node={$node}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanupWarning(string $node): array
    {
        return [
            'code' => 'proxy.cleanup_deferred',
            'family' => 'proxy',
            'message' => 'Proxy route intent was removed, but backend/TLS cleanup is deferred to proxy doctor fix mode.',
            'next_command' => "doctor --family=proxy --restore --node={$node}",
        ];
    }
}
