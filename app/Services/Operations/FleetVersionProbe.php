<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Contracts\RemoteShell;
use App\Data\Operations\FleetVersionReport;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;

/**
 * Probes the current Orbit version of the gateway and each selected workload
 * node for the `update:all` fleet version check.
 *
 * The gateway version is read from the baked `app.version` config (the gateway
 * is the local target). Each workload node version is read remotely with
 * `orbit --version` through gateway-owned node execution, reusing the same
 * {@see RemoteShell} transport as {@see FleetUpdateVerifier}. A version that
 * cannot be read or parsed is reported as `null` and counts as outdated so the
 * node is still updated rather than silently skipped.
 */
final readonly class FleetVersionProbe
{
    private const string VersionCommand = 'orbit --version';

    private const int VersionTimeoutSeconds = 30;

    public function __construct(
        private RemoteShell $remoteShell,
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

        foreach ($this->targets->workloadNodes() as $node) {
            $version = $this->nodeVersion($node, $operationRun);
            $nodeVersions[$node->name] = $version;

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

    private function parseVersion(string $output): ?string
    {
        if (preg_match('/(\d+\.\d+\.\d+(?:[A-Za-z0-9.+-]*)?)/', $output, $matches) !== 1) {
            return null;
        }

        return $this->normalize($matches[1]);
    }

    private function normalize(string $version): ?string
    {
        $version = ltrim(trim($version), 'v');

        if ($version === '' || $version === '0.0.0') {
            return null;
        }

        return $version;
    }
}
