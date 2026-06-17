<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use InvalidArgumentException;
use Throwable;

/**
 * Provides node-family and tool-family doctor drift checks for the `s3` role.
 *
 * Execution lane: RemoteHostExecutor (SSH host substrate + Docker inspection).
 * See apps/docs/content/execution-lanes.md — all remote work here targets the
 * host substrate via `docker inspect` / container env probing. No gateway-container
 * exec, no host sqlite3/python3/php artisan forwarding.
 *
 * Node family owns:
 *  - WireGuard address presence (node.s3.wireguard_missing)
 *  - data_path setting validity (node.s3_data_path_invalid)
 *
 * Tool family owns:
 *  - SeaweedFS tool row existence (tool.seaweedfs.row_missing)
 *  - SeaweedFS credential completeness (tool.seaweedfs.credentials_missing)
 *  - SeaweedFS runtime container presence/divergence (tool.seaweedfs.runtime_container_missing)
 *  - SeaweedFS bind address posture (tool.seaweedfs.bind_public_interface)
 */
final readonly class S3DoctorProbe
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * Node-family drift checks for an active s3 role assignment.
     *
     * Lane: RemoteHostExecutor — probes container env via `docker inspect`.
     * See apps/docs/content/execution-lanes.md.
     *
     * @return list<DriftEntry>
     */
    public function nodeDrift(Node $node, NodeRoleAssignment $assignment): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkWireGuardAddress($node, $assignment));
        $drift = array_merge($drift, $this->checkDataPath($node, $assignment));

        return $drift;
    }

    /**
     * Tool-family drift checks for an active s3 role assignment.
     *
     * Lane: RemoteHostExecutor — probes container lifecycle and env via
     * `docker inspect`. See apps/docs/content/execution-lanes.md.
     *
     * @return list<DriftEntry>
     */
    public function toolDrift(Node $node, NodeRoleAssignment $assignment): array
    {
        $drift = [];

        $seaweedfsTool = $this->seaweedfsToolFor($node);

        if (! $seaweedfsTool instanceof NodeTool) {
            $drift[] = new DriftEntry(
                family: 'tool',
                key: 'tool.seaweedfs.row_missing',
                kind: DriftKind::Missing,
                summary: "No seaweedfs tool row exists on s3 node {$node->name}.",
                detail: [
                    'role' => $assignment->role,
                    'node' => $node->name,
                ],
            );

            return $drift;
        }

        $drift = array_merge($drift, $this->checkCredentials($node, $assignment, $seaweedfsTool));

        $probe = $this->containerProbe($node);

        if (! $probe->successful()) {
            $drift[] = new DriftEntry(
                family: 'tool',
                key: 'tool.seaweedfs.runtime_container_missing',
                kind: DriftKind::Unverifiable,
                summary: "SeaweedFS runtime container could not be verified on node {$node->name}.",
                detail: [
                    'role' => $assignment->role,
                    'container' => S3RuntimeContainer::ContainerName,
                    'reason' => 'probe_failed',
                    'exit_code' => $probe->exitCode,
                    'stderr' => trim($probe->stderr),
                ],
            );

            return $drift;
        }

        $state = $this->parseKeyValueOutput($probe->stdout);

        if (($state['exists'] ?? null) !== '1' || ($state['running'] ?? null) !== 'true') {
            $exists = ($state['exists'] ?? null) === '1';
            $drift[] = new DriftEntry(
                family: 'tool',
                key: 'tool.seaweedfs.runtime_container_missing',
                kind: $exists ? DriftKind::Divergent : DriftKind::Missing,
                summary: 'SeaweedFS runtime container is '.($exists ? 'stopped' : 'absent')." on node {$node->name}.",
                detail: [
                    'role' => $assignment->role,
                    'container' => S3RuntimeContainer::ContainerName,
                    'reason' => $exists ? 'container_stopped' : 'container_absent',
                    'exists' => $state['exists'] ?? null,
                    'running' => $state['running'] ?? null,
                ],
            );
        }

        $drift = array_merge($drift, $this->checkBindPosture($node, $assignment, $state));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkWireGuardAddress(Node $node, NodeRoleAssignment $assignment): array
    {
        $address = trim((string) $node->wireguard_address);

        if ($address !== '') {
            return [];
        }

        return [
            new DriftEntry(
                family: 'node',
                key: 'node.s3.wireguard_missing',
                kind: DriftKind::Missing,
                summary: "Active s3 role node {$node->name} has a missing or empty WireGuard address.",
                detail: [
                    'role' => $assignment->role,
                    'node' => $node->name,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDataPath(Node $node, NodeRoleAssignment $assignment): array
    {
        $settings = is_array($assignment->settings) ? $assignment->settings : [];

        try {
            S3RoleSettings::fromArray($settings);

            return [];
        } catch (InvalidArgumentException) {
            return [
                new DriftEntry(
                    family: 'node',
                    key: 'node.s3_data_path_invalid',
                    kind: DriftKind::Missing,
                    summary: "Active s3 role assignment on node {$node->name} has a missing, relative, or invalid data_path setting.",
                    detail: [
                        'role' => $assignment->role,
                        'node' => $node->name,
                        'data_path' => $settings['data_path'] ?? null,
                    ],
                ),
            ];
        }
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCredentials(Node $node, NodeRoleAssignment $assignment, NodeTool $seaweedfsTool): array
    {
        if ($this->hasCompleteCredentials($seaweedfsTool)) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'tool',
                key: 'tool.seaweedfs.credentials_missing',
                kind: DriftKind::Missing,
                summary: "SeaweedFS tool row on node {$node->name} is missing service-level credentials.",
                detail: [
                    'role' => $assignment->role,
                    'node' => $node->name,
                    'tool' => 'seaweedfs',
                ],
            ),
        ];
    }

    /**
     * @param  array<string, string>  $state
     * @return list<DriftEntry>
     */
    private function checkBindPosture(Node $node, NodeRoleAssignment $assignment, array $state): array
    {
        $expectedBind = trim((string) $node->wireguard_address);
        $observedAddress = $this->observedBindAddress($state);

        if ($observedAddress === null) {
            return [];
        }

        $observedHost = $this->extractBindHost($observedAddress);

        if ($observedHost === $expectedBind) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'tool',
                key: 'tool.seaweedfs.bind_public_interface',
                kind: DriftKind::Divergent,
                summary: "SeaweedFS on node {$node->name} is bound to '{$observedHost}' instead of its WireGuard address '{$expectedBind}'.",
                detail: [
                    'role' => $assignment->role,
                    'container' => S3RuntimeContainer::ContainerName,
                    'expected_bind' => $expectedBind,
                    'observed_address' => $observedAddress,
                    'observed_host' => $observedHost,
                ],
            ),
        ];
    }

    private function hasCompleteCredentials(NodeTool $seaweedfsTool): bool
    {
        $credentials = $seaweedfsTool->credentials;

        if (! is_array($credentials)) {
            return false;
        }

        $fields = $credentials['fields'] ?? null;

        if (! is_array($fields)) {
            return false;
        }

        $accessKeyId = $fields['access_key_id'] ?? null;
        $secretAccessKey = $fields['secret_access_key'] ?? null;

        return is_string($accessKeyId) && $accessKeyId !== ''
            && is_string($secretAccessKey) && $secretAccessKey !== '';
    }

    /**
     * Run a remote probe against the orbit-seaweedfs container via docker inspect.
     *
     * Lane: RemoteHostExecutor — SSH host substrate + Docker container inspection.
     * See apps/docs/content/execution-lanes.md.
     *
     * The host-side published port is extracted to determine the bind posture
     * of the container. A bind to the WireGuard address is correct; a bind to
     * 0.0.0.0 or any non-WireGuard address is drift.
     */
    private function containerProbe(Node $node): RemoteShellResult
    {
        $container = S3RuntimeContainer::ContainerName;

        return $this->runProbe($node, sprintf(
            <<<'SH'
# orbit-s3-doctor:runtime-probe
set -eu
container=%s

if ! docker container inspect "$container" >/dev/null 2>&1; then
    printf 'exists=0\nrunning=false\npublished_address=\n'
    exit 0
fi

running="$(docker container inspect --format '{{.State.Running}}' "$container" 2>/dev/null || printf 'false')"
published_address="$(docker container inspect --format '{{range $p, $bindings := .NetworkSettings.Ports}}{{if eq $p "8333/tcp"}}{{range $bindings}}{{printf "%%s:%%s\n" .HostIp .HostPort}}{{end}}{{end}}{{end}}' "$container" 2>/dev/null | head -n 1)"

printf 'exists=1\nrunning=%%s\npublished_address=%%s\n' "$running" "$published_address"
SH,
            escapeshellarg($container),
        ), 's3-runtime-doctor-probe');
    }

    private function runProbe(Node $node, string $script, string $operation): RemoteShellResult
    {
        try {
            return $this->remoteShell->run($node, $script, [
                'throw' => false,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => $operation,
                ],
            ]);
        } catch (Throwable $exception) {
            return new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: $exception->getMessage(),
                durationMs: 0,
            );
        }
    }

    /**
     * @param  array<string, string>  $state
     */
    private function observedBindAddress(array $state): ?string
    {
        $envAddress = trim($state['published_address'] ?? '');

        return $envAddress !== '' ? $envAddress : null;
    }

    private function extractBindHost(string $address): string
    {
        $lastColon = strrpos($address, ':');

        if ($lastColon !== false) {
            return substr($address, 0, $lastColon);
        }

        return $address;
    }

    /**
     * @return array<string, string>
     */
    private function parseKeyValueOutput(string $output): array
    {
        $values = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $value;
        }

        return $values;
    }

    private function seaweedfsToolFor(Node $node): ?NodeTool
    {
        return NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'seaweedfs')
            ->first();
    }
}
