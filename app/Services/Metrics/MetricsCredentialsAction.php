<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Support\Str;

final readonly class MetricsCredentialsAction
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $nodeAccessAuthorizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(Node $caller, ?string $nodeName): array
    {
        $metricsNode = $this->resolveMetricsNode($nodeName);

        if (! $metricsNode instanceof Node) {
            return $this->error(
                'validation_failed',
                'An active metrics role node is required.',
                ['field' => 'node', 'required_role' => 'metrics'],
                422,
            );
        }

        if (! $this->callerCanReadCredentials($caller, $metricsNode)) {
            return $this->error(
                'authorization_failed',
                'This node is not authorized to read credentials for the selected metrics node.',
                $this->authorizationMeta($metricsNode),
                403,
            );
        }

        $process = $this->grafanaProcess($metricsNode);

        if (! $process instanceof Process) {
            return $this->missingCredentials($metricsNode);
        }

        $credentials = $this->credentialsFromProcess($metricsNode, $process);

        if ($credentials === null) {
            return $this->missingCredentials($metricsNode);
        }

        return $this->success($credentials);
    }

    /**
     * @return array<string, mixed>
     */
    public function reset(Node $caller, ?string $nodeName): array
    {
        $metricsNode = $this->resolveMetricsNode($nodeName);

        if (! $metricsNode instanceof Node) {
            return $this->error(
                'validation_failed',
                'An active metrics role node is required.',
                ['field' => 'node', 'required_role' => 'metrics'],
                422,
            );
        }

        if (! $this->callerCanReadCredentials($caller, $metricsNode)) {
            return $this->error(
                'authorization_failed',
                'This node is not authorized to reset credentials for the selected metrics node.',
                $this->authorizationMeta($metricsNode),
                403,
            );
        }

        $process = $this->grafanaProcess($metricsNode);

        if (! $process instanceof Process) {
            return $this->missingCredentials($metricsNode);
        }

        $runtimeConfig = is_array($process->runtime_config) ? $process->runtime_config : [];
        $environment = is_array($runtimeConfig['environment'] ?? null) ? $runtimeConfig['environment'] : [];
        $credentials = is_array($runtimeConfig['credentials'] ?? null) ? $runtimeConfig['credentials'] : [];
        $password = Str::random(32);

        $runtimeConfig['environment'] = [
            ...$environment,
            'GF_SECURITY_ADMIN_USER' => 'admin',
            'GF_SECURITY_ADMIN_PASSWORD' => $password,
            'GF_SERVER_ROOT_URL' => 'https://metrics.orbit',
        ];
        $runtimeConfig['credentials'] = [
            ...$credentials,
            'admin_user' => 'admin',
            'admin_password' => $password,
            'url' => 'https://metrics.orbit',
        ];

        $this->refreshSpecHash($runtimeConfig);

        $process->forceFill([
            'runtime_config' => $runtimeConfig,
        ])->save();

        return $this->success([
            'node' => $metricsNode->name,
            'url' => 'https://metrics.orbit',
            'admin_user' => 'admin',
            'admin_password' => $password,
        ]);
    }

    private function resolveMetricsNode(?string $nodeName): ?Node
    {
        $metricsNodeIds = $this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::Metrics->value);

        if ($metricsNodeIds === []) {
            return null;
        }

        if ($nodeName !== null) {
            return Node::query()
                ->where('name', $nodeName)
                ->where('status', NodeStatus::Active->value)
                ->whereIn('id', $metricsNodeIds)
                ->first();
        }

        $nodes = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $metricsNodeIds)
            ->limit(2)
            ->get();

        if ($nodes->count() === 1) {
            return $nodes->first();
        }

        return null;
    }

    private function callerCanReadCredentials(Node $caller, Node $metricsNode): bool
    {
        if ($this->nodeRoleAssignments->nodeIsGateway($caller)) {
            return true;
        }

        return $this->nodeAccessAuthorizer->allows($caller, $metricsNode, 'tool:credentials');
    }

    /**
     * @return array<string, string>
     */
    private function authorizationMeta(Node $metricsNode): array
    {
        return [
            'reason' => 'missing_permission',
            'missing_permission' => 'tool:credentials',
            'serving_node' => $metricsNode->name,
            'process' => 'grafana',
        ];
    }

    private function grafanaProcess(Node $metricsNode): ?Process
    {
        return Process::query()
            ->where('node_id', $metricsNode->id)
            ->where('owner_type', $metricsNode->getMorphClass())
            ->where('owner_id', $metricsNode->id)
            ->where('name', 'grafana')
            ->first();
    }

    /**
     * @return array{node: string, url: string, admin_user: string, admin_password: string}|null
     */
    private function credentialsFromProcess(Node $metricsNode, Process $process): ?array
    {
        $runtimeConfig = is_array($process->runtime_config) ? $process->runtime_config : [];
        $credentials = is_array($runtimeConfig['credentials'] ?? null) ? $runtimeConfig['credentials'] : [];

        $adminUser = $this->filledString($credentials['admin_user'] ?? null) ?? 'admin';
        $adminPassword = $this->filledString($credentials['admin_password'] ?? null);
        $url = $this->filledString($credentials['url'] ?? null) ?? 'https://metrics.orbit';

        if ($adminPassword === null) {
            return null;
        }

        return [
            'node' => $metricsNode->name,
            'url' => $url,
            'admin_user' => $adminUser,
            'admin_password' => $adminPassword,
        ];
    }

    private function filledString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     */
    private function refreshSpecHash(array &$runtimeConfig): void
    {
        unset($runtimeConfig['spec_hash'], $runtimeConfig['labels']);

        $specHash = $this->specHash([
            ...$runtimeConfig,
            'runtime' => ProcessRuntime::DockerSwarm->value,
            'process' => 'grafana',
        ]);

        $runtimeConfig['spec_hash'] = $specHash;
        $runtimeConfig['labels'] = [
            'orbit.managed' => 'true',
            'orbit.process' => 'grafana',
            'orbit.process.definition' => (string) ($runtimeConfig['definition'] ?? 'grafana'),
            'orbit.process.version_family' => (string) ($runtimeConfig['version_family'] ?? ''),
            'orbit.process.version' => (string) ($runtimeConfig['version'] ?? ''),
            'orbit.process.spec_hash' => $specHash,
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function specHash(array $spec): string
    {
        ksort($spec);

        return substr(hash('sha256', json_encode($spec, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    private function success(array $credentials): array
    {
        return [
            'success' => [
                'data' => [
                    'credentials' => $credentials,
                ],
                'meta' => [
                    'process' => 'grafana',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function missingCredentials(Node $metricsNode): array
    {
        return $this->error(
            'metrics.credentials_missing',
            "Grafana credentials are missing for '{$metricsNode->name}'.",
            [
                'node' => $metricsNode->name,
                'process' => 'grafana',
                'next_command' => "doctor --family=process --restore --node={$metricsNode->name}",
            ],
            422,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
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
