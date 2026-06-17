<?php

declare(strict_types=1);

namespace App\Services\RuntimeBackend;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RuntimeBackend\GatewayRuntimeBackendProbeResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Services\Proxy\ProxyRouteProbe;
use App\Services\Runtime\OrbitContainerNames;

/**
 * Gateway-only runtime backend probe.
 *
 * Inspects the gateway `orbit-gateway` container state over SSH. This probe
 * is scoped to gateway nodes only; app-host nodes use {@see RuntimeBackendProbe}
 * for the host process runtime.
 *
 * Mirrors the structured output pattern from {@see ProxyRouteProbe::introspectCaddyContainer}.
 */
final readonly class GatewayRuntimeBackendProbe
{
    public function __construct(
        private RemoteShell $remoteShell,
        private OrbitContainerNames $containerNames = new OrbitContainerNames,
    ) {}

    public function check(Node $node): GatewayRuntimeBackendProbeResult
    {
        $result = $this->remoteShell->run($node, $this->script(), ['timeout' => 15, 'throw' => false]);
        $parts = explode("\t", trim($result->output()), 3);
        $runtimeStatus = ($parts[0] ?? '') !== '' ? $parts[0] : 'unknown';

        return new GatewayRuntimeBackendProbeResult(
            runtimeStatus: $runtimeStatus,
            containerExists: ($parts[1] ?? '') === 'true',
            containerRunning: ($parts[2] ?? '') === 'true',
            exitCode: $result->exitCode,
            output: trim($result->output()),
        );
    }

    public function remoteShell(): RemoteShell
    {
        return $this->remoteShell;
    }

    /**
     * Compare the observed orbit-gateway container state against the
     * expectation that it must exist and be running.
     *
     * @return list<DriftEntry>
     */
    public function diff(Node $node, ProbeSnapshot $snapshot): array
    {
        $runtimeName = $this->containerNames->gateway();
        $observed = $snapshot->get($runtimeName);

        if (! is_array($observed)) {
            return [];
        }

        $runtimeStatus = is_string($observed['runtime_status'] ?? null)
            ? $observed['runtime_status']
            : 'available';

        if ($runtimeStatus !== 'available') {
            return [
                new DriftEntry(
                    family: 'node',
                    key: 'node.docker_runtime_unavailable',
                    kind: DriftKind::Divergent,
                    summary: $runtimeStatus === 'no_docker'
                        ? "Docker CLI is not installed on {$node->name}; orbit-gateway cannot be probed."
                        : "Docker daemon is unreachable on {$node->name}; orbit-gateway cannot be probed.",
                    detail: [
                        'container' => $runtimeName,
                        'node' => $node->name,
                        'runtime_status' => $runtimeStatus,
                    ],
                ),
            ];
        }

        if (($observed['container_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: 'node',
                    key: 'node.runtime_container_missing',
                    kind: DriftKind::Missing,
                    summary: "Gateway container {$runtimeName} is missing on {$node->name}.",
                    detail: [
                        'container' => $runtimeName,
                        'node' => $node->name,
                    ],
                ),
            ];
        }

        if (($observed['container_running'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: 'node',
                    key: 'node.runtime_container_stopped',
                    kind: DriftKind::Divergent,
                    summary: "Gateway container {$runtimeName} is not running on {$node->name}.",
                    detail: [
                        'container' => $runtimeName,
                        'node' => $node->name,
                    ],
                ),
            ];
        }

        return [];
    }

    private function script(): string
    {
        $runtimeName = escapeshellarg($this->containerNames->gateway());

        return sprintf(
            <<<'BASH'
# orbit-gateway-container-probe:container-inspect
container=%s
runtime="available"
exists="false"
running="false"

if ! command -v docker >/dev/null 2>&1; then
    runtime="no_docker"
elif ! docker info >/dev/null 2>&1; then
    runtime="daemon_unavailable"
else
    if docker container inspect --format '{{.State.Running}}' "$container" >/dev/null 2>&1; then
        exists="true"
        state=$(docker container inspect --format '{{.State.Running}}' "$container" 2>/dev/null || echo "false")
        if [ "$state" = "true" ]; then
            running="true"
        fi
    fi
fi
printf '%%s\t%%s\t%%s\n' "$runtime" "$exists" "$running"
BASH,
            $runtimeName,
        );
    }
}
