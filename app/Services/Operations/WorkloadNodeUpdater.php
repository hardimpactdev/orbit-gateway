<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleName;
use App\Exceptions\UpdateLeaseConflict;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class WorkloadNodeUpdater
{
    private const string DoctorCommand = 'orbit doctor --self --json';

    private const int DoctorTimeoutSeconds = 120;

    public function __construct(
        private NodeRoleAssignments $roles,
        private RemoteShell $remoteShell,
        private UpdateLeaseManager $leases,
        private OperationRunRecorder $operationRuns,
        private FleetUpdateTargetSelector $targets,
        private FleetVersionProbe $fleetVersions,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): array
    {
        $results = [];

        foreach ($this->targets->workloadNodes() as $node) {
            $results[] = $this->updateNode($operationRun, $plan, $node);
        }

        return $results;
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

        $status = $result['status'] ?? null;

        if ($status === 'skipped') {
            $this->operationRuns->appendStep($operationRun->id, $this->eventKey($node), 'done', "Workload node {$node->name} skipped: already up to date");

            return $result;
        }

        if ($status === 'completed') {
            $this->operationRuns->appendStep(
                $operationRun->id,
                $this->eventKey($node),
                'done',
                $this->updatedMessage($node, $result['doctor_issues'] ?? null),
            );

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
        if ($this->fleetVersions->isCurrent($this->fleetVersions->nodeVersion($node, $operationRun), $plan->target_version)) {
            return [
                ...$this->targetPayload($node),
                'status' => 'skipped',
            ];
        }

        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'running',
            "Downloading {$plan->target_version}",
        );

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

        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'running',
            'Replacing cli binary',
        );

        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'running',
            'Running doctor',
        );

        return [
            ...$this->targetPayload($node),
            'status' => 'completed',
            'doctor_issues' => $this->runNodeDoctor($operationRun, $node),
        ];
    }

    /**
     * Run `orbit doctor` in verify mode for the node as the final per-node step.
     * The verify is non-fatal: a non-zero issue count is surfaced per node but
     * does not by itself fail the node's update, and any failure to resolve the
     * count yields `null` (unknown).
     */
    private function runNodeDoctor(OperationRun $operationRun, Node $node): ?int
    {
        $result = $this->remoteShell->run($node, self::DoctorCommand, [
            'cwd' => $node->orbit_path,
            'timeout' => self::DoctorTimeoutSeconds,
            'metadata' => [
                'ORBIT_OPERATION_ID' => $operationRun->id,
            ],
        ]);

        return $this->doctorIssuesFromOutput($result->output());
    }

    private function doctorIssuesFromOutput(string $output): ?int
    {
        $output = trim($output);

        if ($output === '') {
            return null;
        }

        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $envelope = $decoded['success'] ?? $decoded['error'] ?? null;
        $data = is_array($envelope) ? ($envelope['data'] ?? null) : null;
        $doctor = is_array($data) ? ($data['doctor'] ?? null) : null;
        $summary = is_array($doctor) ? ($doctor['summary'] ?? null) : null;
        $issues = is_array($summary) ? ($summary['issues'] ?? null) : null;

        return is_int($issues) ? $issues : null;
    }

    private function updatedMessage(Node $node, ?int $issues): string
    {
        if ($issues === null || $issues === 0) {
            return "Workload node {$node->name} updated";
        }

        $noun = $issues === 1 ? 'issue' : 'issues';

        return "Workload node {$node->name} updated ({$issues} {$noun})";
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
            'echo reconcile_launcher',
            'resolved="$(command -v orbit 2>/dev/null || true)"',
            'case "$resolved" in',
            '    /*)',
            '        if [ "$resolved" != "$BIN_PATH" ] && [ "$(readlink -f "$resolved" 2>/dev/null || true)" != "$(readlink -f "$INSTALL_ROOT/bin/orbit-binary" 2>/dev/null || true)" ]; then',
            '            if [ -w "$(dirname "$resolved")" ]; then',
            '                ln -sfn "$INSTALL_ROOT/bin/orbit-binary" "$resolved" || true',
            '            else',
            '                sudo -n ln -sfn "$INSTALL_ROOT/bin/orbit-binary" "$resolved" || true',
            '            fi',
            '        fi',
            '        ;;',
            'esac',
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
        return $this->roles->assignmentRoleLabel($node);
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
