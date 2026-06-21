<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Contracts\RemoteShell;
use App\Data\Operations\FleetVersionReport;
use App\Data\RemoteShell\RemoteShellPoolJob;
use App\Data\RemoteShell\RemoteShellPoolResult;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\RemoteShell\RemoteShellPool;
use JsonException;

/**
 * Probes the current Orbit version of the gateway and each selected workload
 * node for the `update:all` fleet version check.
 *
 * The gateway version is read from the baked `app.version` config (the gateway
 * is the local target). Each workload node version is read remotely with the
 * local-only `orbit --version --local --json` path through gateway-owned node
 * execution, reusing the same {@see RemoteShell} transport as
 * {@see FleetUpdateVerifier}. A version
 * that cannot be read or parsed is reported as `null` and counts as outdated so
 * the node is still updated rather than silently skipped.
 */
final readonly class FleetVersionProbe
{
    private const string VersionCommand = 'orbit --version --local --json';

    private const int VersionTimeoutSeconds = 10;

    private const int DefaultConcurrency = 4;

    public function __construct(
        private RemoteShell $remoteShell,
        private RemoteShellPool $remoteShellPool,
        private FleetUpdateTargetSelector $targets,
    ) {}

    public function probe(OperationRun $operationRun, OperationUpdatePlan $plan): FleetVersionReport
    {
        $target = $plan->target_version;
        $gatewayVersion = $this->gatewayVersion();

        $nodeVersions = [];
        $outdated = 0;

        if (! $this->isCurrent($gatewayVersion, $target)) {
            $outdated++;
        }

        foreach ($this->probeWorkloadNodeVersions($operationRun) as $nodeName => $version) {
            $nodeVersions[$nodeName] = $version;

            if (! $this->isCurrent($version, $target)) {
                $outdated++;
            }
        }

        return new FleetVersionReport(
            targetVersion: $target,
            gatewayVersion: $gatewayVersion,
            nodeVersions: $nodeVersions,
            outdatedCount: $outdated,
        );
    }

    /**
     * Resolve the gateway's currently running Orbit version, or `null` when it
     * is unknown (the placeholder `0.0.0`).
     */
    public function gatewayVersion(): ?string
    {
        return $this->normalize((string) config('app.version', '0.0.0'));
    }

    /**
     * Resolve a workload node's current Orbit version, or `null` when it cannot
     * be read or parsed.
     */
    public function nodeVersion(Node $node, OperationRun $operationRun): ?string
    {
        $result = $this->remoteShell->run($node, self::VersionCommand, [
            'cwd' => $node->orbit_path,
            'timeout' => self::VersionTimeoutSeconds,
            'metadata' => [
                'ORBIT_OPERATION_ID' => $operationRun->id,
            ],
        ]);

        if (! $result->successful()) {
            return null;
        }

        return $this->parseVersion($result->output());
    }

    public function isCurrent(?string $version, string $target): bool
    {
        return $version !== null && version_compare($version, $target, '==');
    }

    /**
     * @return array<string, ?string>
     */
    private function probeWorkloadNodeVersions(OperationRun $operationRun): array
    {
        $nodes = $this->targets->workloadNodes();

        if ($nodes->isEmpty()) {
            return [];
        }

        $jobs = [];

        foreach ($nodes as $node) {
            $jobs[] = new RemoteShellPoolJob(
                key: $node->name,
                node: $node,
                script: self::VersionCommand,
                options: [
                    'cwd' => $node->orbit_path,
                    'timeout' => self::VersionTimeoutSeconds,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => $operationRun->id,
                    ],
                ],
            );
        }

        $versions = [];

        foreach ($this->remoteShellPool->run($jobs, $this->concurrency()) as $result) {
            $versions[$result->key] = $this->versionFromPoolResult($result);
        }

        return $versions;
    }

    private function versionFromPoolResult(RemoteShellPoolResult $result): ?string
    {
        if ($result->exception !== null || $result->result === null || ! $result->result->successful()) {
            return null;
        }

        return $this->parseVersion($result->result->output());
    }

    private function parseVersion(string $output): ?string
    {
        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $success = $decoded['success'] ?? null;

        if (! is_array($success)) {
            return null;
        }

        $data = $success['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        $version = $data['version'] ?? null;

        if (! is_string($version)) {
            return null;
        }

        return $this->normalize($version);
    }

    private function normalize(string $version): ?string
    {
        $version = ltrim(trim($version), 'v');

        if ($version === '' || $version === '0.0.0') {
            return null;
        }

        return $version;
    }

    private function concurrency(): int
    {
        $concurrency = (int) config('orbit.updates.fleet_version_probe_concurrency', self::DefaultConcurrency);

        return max(1, $concurrency);
    }
}
