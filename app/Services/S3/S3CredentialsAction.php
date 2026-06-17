<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class S3CredentialsAction
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $nodeAccessAuthorizer,
    ) {}

    /**
     * Read SeaweedFS service-level credentials and endpoint metadata for the selected s3 node.
     *
     * Returns a success or error array. The error array always includes a `status` key
     * with the HTTP status code to use in the response.
     *
     * @return array{
     *     success: array{
     *         data: array{credentials: array{
     *             node: string,
     *             private_endpoint: string,
     *             public_endpoints: list<string>,
     *             region: string,
     *             access_key_id: string,
     *             secret_access_key: string,
     *             bucket_endpoint_style: string,
     *             backend_pool: list<string>
     *         }},
     *         meta: array{tool: string}
     *     }
     * }|array{
     *     error: array{code: string, message: string, meta: array<string, mixed>, status: int}
     * }
     */
    public function read(Node $caller, ?string $nodeName): array
    {
        // Resolve the selected active s3 node.
        $s3Node = $this->resolveS3Node($caller, $nodeName);

        if ($s3Node === null) {
            return $this->error(
                'validation_failed',
                'An active s3 role node is required.',
                ['field' => 'node', 'required_role' => 's3'],
                422,
            );
        }

        // Authorize the caller for tool:credentials on the selected s3 node.
        if (! $this->nodeRoleAssignments->nodeIsGateway($caller)) {
            if (! $this->nodeAccessAuthorizer->allows($caller, $s3Node, 'tool:credentials')) {
                return $this->error(
                    'authorization_failed',
                    'This node is not authorized to read credentials for the selected s3 node.',
                    [],
                    403,
                );
            }
        }

        // Validate that an active router exists.
        $router = $this->nodeRoleAssignments->activeRouterNodeQuery()->orderBy('id')->first();

        if (! $router instanceof Node) {
            return $this->error(
                'validation_failed',
                'An active router role is required.',
                ['field' => 'router', 'required_role' => 'router'],
                422,
            );
        }

        // Read the seaweedfs tool row for this s3 node.
        $seaweedfs = NodeTool::query()
            ->where('node_id', $s3Node->id)
            ->where('name', 'seaweedfs')
            ->first();

        if (! $seaweedfs instanceof NodeTool) {
            return $this->error(
                's3.credentials_missing',
                "SeaweedFS service credentials are missing for '{$s3Node->name}'.",
                [
                    'node' => $s3Node->name,
                    'tool' => 'seaweedfs',
                    'next_command' => "doctor --family=tool --restore --node={$s3Node->name}",
                ],
                422,
            );
        }

        // Extract credentials from the seaweedfs tool row.
        $credentials = is_array($seaweedfs->credentials) ? $seaweedfs->credentials : [];
        $fields = is_array($credentials['fields'] ?? null) ? $credentials['fields'] : [];

        $accessKeyId = is_string($fields['access_key_id'] ?? null) ? $fields['access_key_id'] : '';
        $secretAccessKey = is_string($fields['secret_access_key'] ?? null) ? $fields['secret_access_key'] : '';

        if ($accessKeyId === '' || $secretAccessKey === '') {
            return $this->error(
                's3.credentials_missing',
                "SeaweedFS service credentials are missing for '{$s3Node->name}'.",
                [
                    'node' => $s3Node->name,
                    'tool' => 'seaweedfs',
                    'next_command' => "doctor --family=tool --restore --node={$s3Node->name}",
                ],
                422,
            );
        }

        // Read optional fields with documented fallbacks.
        $region = is_string($fields['region'] ?? null) && $fields['region'] !== ''
            ? $fields['region']
            : S3ServiceConfig::Region;

        $bucketStyle = is_string($fields['bucket_style'] ?? null) && $fields['bucket_style'] !== ''
            ? $fields['bucket_style']
            : 'path';

        // Build public endpoints from seaweedfs tool config.
        $config = is_array($seaweedfs->config) ? $seaweedfs->config : [];
        $publicHosts = is_array($config['public_hosts'] ?? null) ? $config['public_hosts'] : [];

        /** @var list<string> $publicHosts */
        $publicHosts = array_values(array_filter($publicHosts, is_string(...)));
        $publicEndpoints = array_map(fn (string $h): string => "https://{$h}", $publicHosts);

        // Build backend pool from the router-owned s3.orbit ProxyRoute upstreams.
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

        return [
            'success' => [
                'data' => [
                    'credentials' => [
                        'node' => $s3Node->name,
                        'private_endpoint' => S3RouteRegistrar::ServiceEndpoint,
                        'public_endpoints' => $publicEndpoints,
                        'region' => $region,
                        'access_key_id' => $accessKeyId,
                        'secret_access_key' => $secretAccessKey,
                        'bucket_endpoint_style' => $bucketStyle,
                        'backend_pool' => $backendPool,
                    ],
                ],
                'meta' => [
                    'tool' => 'seaweedfs',
                ],
            ],
        ];
    }

    /**
     * Resolve a single active s3 node. When $nodeName is provided, look it up
     * by name. When null, auto-resolve if exactly one active s3 node is visible.
     */
    private function resolveS3Node(Node $caller, ?string $nodeName): ?Node
    {
        $s3NodeIds = $this->nodeRoleAssignments->activeNodeIdsForRole('s3');

        if ($s3NodeIds === []) {
            return null;
        }

        if ($nodeName !== null) {
            return Node::query()
                ->where('name', $nodeName)
                ->where('status', NodeStatus::Active->value)
                ->whereIn('id', $s3NodeIds)
                ->first();
        }

        // Auto-resolve when exactly one active s3 node is visible.
        if ($this->nodeRoleAssignments->nodeIsGateway($caller)) {
            $nodes = Node::query()
                ->where('status', NodeStatus::Active->value)
                ->whereIn('id', $s3NodeIds)
                ->limit(2)
                ->get();

            if ($nodes->count() === 1) {
                return $nodes->first();
            }

            return null;
        }

        $visibleNodes = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $s3NodeIds)
            ->get();

        if ($visibleNodes->count() === 1) {
            return $visibleNodes->first();
        }

        return null;
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
