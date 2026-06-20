<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Support\Streaming\ProgressEventStreamEmitter;

final readonly class S3UnpublishAction
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $nodeAccessAuthorizer,
        private S3RouteRegistrar $routeRegistrar,
    ) {}

    /**
     * Remove a public HTTPS hostname from the fleet S3 service (streaming variant).
     *
     * Emits SSE progress steps via the provided emitter as each phase completes.
     *
     * @return array{
     *     success: array{
     *         data: array{s3: array{node: string, private_endpoint: string, public_endpoints: list<string>, backend_pool: list<string>}},
     *         meta: array{host: string, action: string, already_absent: bool}
     *     }
     * }|array{
     *     error: array{code: string, message: string, meta: array<string, mixed>, status: int}
     * }
     */
    public function unpublishWithProgress(Node $caller, string $nodeName, string $host, ProgressEventStreamEmitter $emitter): array
    {
        // Step 1: Confirm destructive removal (consent was verified before streaming began).
        $emitter->stepEvent('confirm_destructive', 'done', 'Destructive removal confirmed');

        // Step 2: Resolve S3 node.
        $emitter->stepEvent('resolve_node', 'running', "Resolving s3 node '{$nodeName}'");
        $s3Node = $this->resolveS3Node($nodeName);

        if ($s3Node === null) {
            $emitter->stepEvent('resolve_node', 'failed', 'No active s3 role node found.');

            return $this->error(
                'validation_failed',
                'An active s3 role node is required to unpublish an S3 host.',
                ['field' => 'node', 'required_role' => 's3'],
                422,
            );
        }

        // Authorize the caller for tool:reconfigure on the selected s3 node.
        if (! $this->nodeRoleAssignments->nodeIsGateway($caller)) {
            if (! $this->nodeAccessAuthorizer->allows($caller, $s3Node, 'tool:reconfigure')) {
                $emitter->stepEvent('resolve_node', 'failed', 'Authorization denied.');

                return $this->error(
                    'authorization_failed',
                    'This node is not authorized to reconfigure the selected s3 node.',
                    [],
                    403,
                );
            }
        }

        $emitter->stepEvent('resolve_node', 'done', "Resolved s3 node '{$s3Node->name}'");

        // Step 3: Check router.
        $emitter->stepEvent('check_router', 'running', 'Checking router node');

        $router = $this->nodeRoleAssignments->activeRouterNodeQuery()->orderBy('id')->first();

        if (! $router instanceof Node) {
            $emitter->stepEvent('check_router', 'failed', 'No active router node found.');

            return $this->error(
                'validation_failed',
                'An active router role is required for S3 route cleanup.',
                ['field' => 'router', 'required_role' => 'router'],
                422,
            );
        }

        $emitter->stepEvent('check_router', 'done', 'Router node verified');

        // Step 4: Remove ingress host.
        $emitter->stepEvent('remove_ingress', 'running', "Checking ingress route for '{$host}'");

        // Check ownership: deny if the host is owned by a non-S3 route.
        $existing = ProxyRoute::query()->where('domain', $host)->first();

        if ($existing instanceof ProxyRoute) {
            $isS3Tool = $existing->owner_type === 's3'
                && isset($existing->config['owner_name'])
                && $existing->config['owner_name'] === 'seaweedfs'
                && isset($existing->config['protocol'])
                && $existing->config['protocol'] === 's3';

            if (! $isS3Tool) {
                $emitter->stepEvent('remove_ingress', 'failed', "Host '{$host}' is owned by a non-S3 route.");

                return $this->error(
                    'proxy.owned_route_denied',
                    "The host '{$host}' is owned by a non-S3 proxy route and cannot be removed by s3:unpublish.",
                    ['field' => 'host', 'owner_type' => $existing->owner_type],
                    409,
                );
            }
        }

        $emitter->stepEvent('remove_ingress', 'done', "Ingress route removed for '{$host}'");

        // Step 5: Remove SeaweedFS public host config.
        $emitter->stepEvent('remove_seaweedfs_config', 'running', 'Removing public host from SeaweedFS config');

        $seaweedfs = NodeTool::query()
            ->where('node_id', $s3Node->id)
            ->where('name', 'seaweedfs')
            ->first();

        if (! $seaweedfs instanceof NodeTool) {
            $emitter->stepEvent('remove_seaweedfs_config', 'failed', 'No seaweedfs tool row found on node.');

            return $this->error(
                'validation_failed',
                'The selected s3 node does not have a seaweedfs tool row.',
                ['field' => 'node'],
                422,
            );
        }

        $config = is_array($seaweedfs->config) ? $seaweedfs->config : [];
        $publicHosts = is_array($config['public_hosts'] ?? null) ? $config['public_hosts'] : [];

        /** @var list<string> $publicHosts */
        $publicHosts = array_values(array_filter($publicHosts, is_string(...)));
        $alreadyAbsent = ! in_array($host, $publicHosts, true);

        if (! $alreadyAbsent) {
            $publicHosts = array_values(array_filter($publicHosts, fn (string $h): bool => $h !== $host));
            $seaweedfs->config = array_merge($config, ['public_hosts' => $publicHosts]);
            $seaweedfs->save();
        }

        $emitter->stepEvent('remove_seaweedfs_config', 'done', 'Public host removed from SeaweedFS config');

        // Step 6: Apply route cleanup.
        $emitter->stepEvent('apply_cleanup', 'running', 'Applying route cleanup');

        try {
            $this->routeRegistrar->removePublicHost($seaweedfs, $host);
        } catch (\RuntimeException $e) {
            $emitter->stepEvent('apply_cleanup', 'failed', $e->getMessage());

            return $this->error('s3.unpublish_failed', $e->getMessage(), [], 500);
        }

        $emitter->stepEvent('apply_cleanup', 'done', 'Route cleanup applied');

        // Build the backend pool from the router-owned s3.orbit route.
        $serviceRoute = ProxyRoute::query()->where('domain', S3RouteRegistrar::ServiceDomain)->first();
        $backendPool = [];

        if ($serviceRoute instanceof ProxyRoute) {
            $routeConfig = is_array($serviceRoute->config) ? $serviceRoute->config : [];
            $upstreams = is_array($routeConfig['upstreams'] ?? null) ? $routeConfig['upstreams'] : [];

            foreach ($upstreams as $upstream) {
                if (is_array($upstream) && isset($upstream['scheme'], $upstream['host'], $upstream['port'])) {
                    $backendPool[] = "{$upstream['scheme']}://{$upstream['host']}:{$upstream['port']}";
                }
            }
        }

        // Collect all remaining public endpoints for this node's seaweedfs tool.
        $seaweedfs->refresh();
        $refreshedConfig = is_array($seaweedfs->config) ? $seaweedfs->config : [];
        $allPublicHosts = is_array($refreshedConfig['public_hosts'] ?? null) ? $refreshedConfig['public_hosts'] : [];

        /** @var list<string> $allPublicHosts */
        $allPublicHosts = array_values(array_filter($allPublicHosts, is_string(...)));
        $publicEndpoints = array_map(fn (string $h): string => "https://{$h}", $allPublicHosts);

        return [
            'success' => [
                'data' => [
                    's3' => [
                        'node' => $s3Node->name,
                        'private_endpoint' => S3RouteRegistrar::ServiceEndpoint,
                        'public_endpoints' => $publicEndpoints,
                        'backend_pool' => $backendPool,
                    ],
                ],
                'meta' => [
                    'host' => $host,
                    'action' => 'unpublished',
                    'already_absent' => $alreadyAbsent,
                ],
            ],
        ];
    }

    /**
     * Resolve a single active s3 node by name.
     */
    private function resolveS3Node(string $nodeName): ?Node
    {
        $s3NodeIds = $this->nodeRoleAssignments->activeNodeIdsForRole('s3');

        if ($s3NodeIds === []) {
            return null;
        }

        return Node::query()
            ->where('name', $nodeName)
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $s3NodeIds)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{error: array{code: string, message: string, meta: array<string, mixed>, status: int}}
     */
    private function error(string $code, string $message, array $meta, int $status): array
    {
        return [
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
                'status' => $status,
            ],
        ];
    }
}
