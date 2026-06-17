<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Exceptions\UpdateLeaseConflict;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Throwable;

final readonly class WorkloadNodeUpdater
{
    public function __construct(
        private NodeRoleAssignments $roles,
        private RemoteShell $remoteShell,
        private UpdateLeaseManager $leases,
        private OperationRunRecorder $operationRuns,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): array
    {
        $results = [];

        foreach ($this->targets() as $node) {
            $results[] = $this->updateNode($operationRun, $plan, $node);
        }

        return $results;
    }

    /**
     * @return Collection<int, Node>
     */
    private function targets(): Collection
    {
        $gatewayIds = $this->roles->activeNodeIdsForRole(NodeRoleName::Gateway->value);

        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $this->roles->activeAppHostNodeIds())
            ->when($gatewayIds !== [], fn ($query) => $query->whereNotIn('id', $gatewayIds))
            ->with('roleAssignments')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function updateNode(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): array
    {
        $this->operationRuns->appendStep($operationRun->id, $this->eventKey($node), 'running', "Updating workload node {$node->name}");

        try {
            $result = $this->leases->withLease(
                resourceType: 'node',
                resourceKey: $node->name,
                operationRun: $operationRun,
                ownerToken: $this->ownerToken($operationRun, $node),
                ttlSeconds: $this->leaseTtlSeconds(),
                callback: fn (): array => $this->runRemoteUpdate($operationRun, $plan, $node),
            );
        } catch (UpdateLeaseConflict $exception) {
            $this->operationRuns->appendStep(
                $operationRun->id,
                $this->eventKey($node),
                'fail',
                $exception->getMessage(),
            );

            throw $exception;
        } catch (Throwable $exception) {
            $result = [
                ...$this->targetPayload($node),
                'status' => 'failed',
                'failed_step' => 'remote_update',
                'output' => $exception->getMessage(),
            ];
        }

        if (($result['status'] ?? null) === 'completed') {
            $this->operationRuns->appendStep($operationRun->id, $this->eventKey($node), 'done', "Workload node {$node->name} updated");

            return $result;
        }

        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'fail',
            is_string($result['output'] ?? null) ? $result['output'] : "Workload node {$node->name} update failed",
        );

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function runRemoteUpdate(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): array
    {
        $script = $this->remoteUpdateScript($plan, $node);
        $result = $this->remoteShell->run($node, $script, [
            'cwd' => $node->orbit_path,
            'timeout' => 300,
            'metadata' => [
                'ORBIT_OPERATION_ID' => $operationRun->id,
            ],
        ]);

        if (! $result->successful()) {
            return [
                ...$this->targetPayload($node),
                'status' => 'failed',
                'failed_step' => 'remote_update',
                'output' => $this->output($result),
            ];
        }

        return [
            ...$this->targetPayload($node),
            'status' => 'completed',
        ];
    }

    private function remoteUpdateScript(OperationUpdatePlan $plan, Node $node): string
    {
        $artifact = $this->cliArtifact($plan, $node);
        $installRoot = rtrim($node->orbit_path, '/') ?: '/home/orbit/orbit';
        $roleImages = $this->requiredRoleImages($plan, $node);

        $lines = [
            'set -euo pipefail',
            'tmp="$(mktemp -d)"',
            'trap \'rm -rf "$tmp"\' EXIT',
            'INSTALL_ROOT="${ORBIT_INSTALL_PATH:-'.$installRoot.'}"',
            'BIN_PATH="${ORBIT_BIN_PATH:-/usr/local/bin/orbit}"',
            'echo download_cli',
            'curl -fsSL '.$this->quote($artifact['url']).' -o "$tmp/orbit"',
            "printf '%s  %s\n' ".$this->quote($artifact['sha256']).' "$tmp/orbit" | sha256sum -c -',
            'echo install_cli',
            'install -d "$INSTALL_ROOT/bin"',
            'install -m 0755 "$tmp/orbit" "$INSTALL_ROOT/bin/orbit-binary"',
            'link_parent="$(dirname "$BIN_PATH")"',
            'if [ -w "$link_parent" ]; then',
            '    ln -sfn "$INSTALL_ROOT/bin/orbit-binary" "$BIN_PATH"',
            'else',
            '    sudo -n ln -sfn "$INSTALL_ROOT/bin/orbit-binary" "$BIN_PATH"',
            'fi',
            'echo verify_cli',
            '"$BIN_PATH" --version',
        ];

        if ($roleImages !== []) {
            $lines[] = 'echo pull_required_images';

            foreach ($roleImages as $image) {
                $lines[] = 'docker pull '.$this->quote($image);
                $lines[] = 'docker image inspect '.$this->quote($image).' >/dev/null';
            }
        }

        $lines[] = 'echo verify';
        $lines[] = '"$BIN_PATH" --version';

        return implode("\n", $lines);
    }

    /**
     * @return array{url: string, sha256: string}
     */
    private function cliArtifact(OperationUpdatePlan $plan, Node $node): array
    {
        $platform = $this->platformKey($node);
        $artifact = $plan->cli_artifacts[$platform] ?? null;

        if (! is_array($artifact) || ! is_string($artifact['url'] ?? null) || ! is_string($artifact['sha256'] ?? null)) {
            throw new RuntimeException("Update plan does not contain a CLI artifact for platform [{$platform}].");
        }

        return [
            'url' => $artifact['url'],
            'sha256' => $artifact['sha256'],
        ];
    }

    private function platformKey(Node $node): string
    {
        $platform = strtolower(trim((string) $node->platform));

        if (str_contains($platform, 'arm64') || str_contains($platform, 'aarch64')) {
            return 'linux-arm64';
        }

        if ($platform === ''
            || str_contains($platform, 'linux')
            || str_contains($platform, 'ubuntu')
            || str_contains($platform, 'debian')
            || str_contains($platform, 'amd64')
            || str_contains($platform, 'x86_64')
            || str_contains($platform, 'x64')) {
            return 'linux-amd64';
        }

        throw new RuntimeException("Unsupported workload update platform [{$node->platform}] for node [{$node->name}].");
    }

    /**
     * @return list<string>
     */
    private function requiredRoleImages(OperationUpdatePlan $plan, Node $node): array
    {
        $images = [];

        if ($this->roles->nodeHostsOrbitCaddy($node) && is_string($plan->role_images['orbit-caddy'] ?? null)) {
            $images[] = $plan->role_images['orbit-caddy'];
        }

        if ($this->roles->nodeHasActiveRole($node, NodeRoleName::WebSocket->value) && is_string($plan->role_images['orbit-websocket'] ?? null)) {
            $images[] = $plan->role_images['orbit-websocket'];
        }

        return array_values(array_unique($images));
    }

    /**
     * @return array{target: string, node: string, role: string}
     */
    private function targetPayload(Node $node): array
    {
        return [
            'target' => $node->name,
            'node' => $node->name,
            'role' => $this->roleLabel($node),
        ];
    }

    private function roleLabel(Node $node): string
    {
        if ($this->roles->nodeHasActiveRole($node, NodeRoleName::AppDevelopment->value)) {
            return NodeRoleName::AppDevelopment->value;
        }

        return NodeRoleName::AppProduction->value;
    }

    private function output(RemoteShellResult $result): string
    {
        return trim($result->errorOutput() !== '' ? $result->errorOutput() : $result->output());
    }

    private function ownerToken(OperationRun $operationRun, Node $node): string
    {
        return hash('sha256', implode(':', [
            'update-runner',
            $operationRun->id,
            'node',
            $node->name,
        ]));
    }

    private function eventKey(Node $node): string
    {
        return "workload.{$node->name}";
    }

    private function leaseTtlSeconds(): int
    {
        $ttlSeconds = (int) config('orbit.updates.lease_ttl_seconds', 300);

        return max(1, $ttlSeconds);
    }

    private function quote(string $value): string
    {
        return escapeshellarg($value);
    }
}
