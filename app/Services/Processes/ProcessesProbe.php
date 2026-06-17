<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Nodes\NodeWireGuardSelfRouteProbe;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use InvalidArgumentException;

final readonly class ProcessesProbe
{
    public function __construct(
        private ?SystemdUnitRenderer $systemdUnitRenderer = null,
        private ?RuntimeBackendProbe $runtimeBackendProbe = null,
        private ?ProcessEventNotifierRenderer $processEventNotifierRenderer = null,
        private ?ProcessDockerContainerRenderer $dockerContainerRenderer = null,
        private ?NodeWireGuardSelfRouteProbe $wireGuardSelfRouteProbe = null,
    ) {}

    public function key(): string
    {
        return 'process';
    }

    public function label(): string
    {
        return 'Processes';
    }

    public function introspect(Process $process): ProbeSnapshot
    {
        $node = $this->processNode($process);

        if (! $node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        if ($this->runtimeFor($process) === ProcessRuntime::Docker) {
            return $this->introspectDocker($process, $node);
        }

        if ($this->runtimeFor($process) === ProcessRuntime::DockerSwarm) {
            return $this->introspectDockerSwarm($process, $node);
        }

        return $this->introspectSystemd($process, $node);
    }

    private function introspectDocker(Process $process, Node $node): ProbeSnapshot
    {
        $shell = $this->runtimeBackendProbe()->remoteShell();
        $expectedUnits = $this->expectedDockerUnitSpecs($process);
        $runtimeUnits = [];
        $backendAvailable = true;
        $backendExitCode = 0;
        $backendOutput = '';

        foreach ($expectedUnits as $unit) {
            $result = $shell->run($node, 'docker container inspect --format \'{{json .}}\' '.escapeshellarg($unit['name']));

            if (! $result->successful()) {
                $stderr = $result->errorOutput();

                if (str_contains($stderr, 'No such container')) {
                    $runtimeUnits[$unit['name']] = [
                        'config_exists' => false,
                        'config_matches' => false,
                        'container_state' => null,
                    ];

                    continue;
                }

                $backendAvailable = false;
                $backendExitCode = $result->exitCode;
                $backendOutput = trim($result->output());

                break;
            }

            $output = trim($result->stdout);

            if ($output === '') {
                $runtimeUnits[$unit['name']] = [
                    'config_exists' => false,
                    'config_matches' => false,
                    'container_state' => null,
                ];

                continue;
            }

            $inspection = json_decode($output, true);

            if (! is_array($inspection)) {
                $runtimeUnits[$unit['name']] = [
                    'config_exists' => false,
                    'config_matches' => false,
                    'container_state' => null,
                ];

                continue;
            }

            $labels = $inspection['Config']['Labels'] ?? [];
            $observedHash = is_array($labels) ? ($labels[$unit['config_hash_label']] ?? null) : null;
            $containerState = $inspection['State']['Status'] ?? null;

            $runtimeUnits[$unit['name']] = [
                'config_exists' => true,
                'config_matches' => $observedHash === $unit['config_hash'],
                'container_state' => $containerState,
            ];
        }

        $runtimeUnitExtras = [];

        if ($backendAvailable) {
            $expectedNames = array_column($expectedUnits, 'name');
            $psResult = $shell->run(
                $node,
                $this->dockerRuntimeUnitExtraCommand($process),
            );

            if ($psResult->successful()) {
                foreach (explode("\n", trim($psResult->stdout)) as $containerName) {
                    $containerName = trim($containerName);

                    if ($containerName !== '' && ! in_array($containerName, $expectedNames, true)) {
                        $runtimeUnitExtras[] = $containerName;
                    }
                }
            }
        }

        return new ProbeSnapshot([
            $process->name => [
                'runtime_backend_available' => $backendAvailable,
                'runtime_backend_exit_code' => $backendExitCode,
                'runtime_backend_output' => $backendOutput,
                'runtime_units' => $runtimeUnits,
                'runtime_unit_extras' => $runtimeUnitExtras,
                'event_notifier' => null,
            ],
        ]);
    }

    private function introspectDockerSwarm(Process $process, Node $node): ProbeSnapshot
    {
        $shell = $this->runtimeBackendProbe()->remoteShell();
        $expectedUnits = $this->expectedDockerSwarmUnitSpecs($process);
        $runtimeUnits = [];
        $backendAvailable = true;
        $backendExitCode = 0;
        $backendOutput = '';

        foreach ($expectedUnits as $unit) {
            $result = $shell->run($node, 'docker service inspect --format \'{{json .}}\' '.escapeshellarg($unit['name']));

            if (! $result->successful()) {
                $message = $result->errorOutput().' '.$result->stdout;

                if (preg_match('/(no such service|not found)/i', $message) === 1) {
                    $runtimeUnits[$unit['name']] = [
                        'config_exists' => false,
                        'config_matches' => false,
                        'service_replicas' => null,
                    ];

                    continue;
                }

                $backendAvailable = false;
                $backendExitCode = $result->exitCode;
                $backendOutput = trim($result->output());

                break;
            }

            $output = trim($result->stdout);

            if ($output === '') {
                $runtimeUnits[$unit['name']] = [
                    'config_exists' => false,
                    'config_matches' => false,
                    'service_replicas' => null,
                ];

                continue;
            }

            $inspection = json_decode($output, true);

            if (! is_array($inspection)) {
                $runtimeUnits[$unit['name']] = [
                    'config_exists' => false,
                    'config_matches' => false,
                    'service_replicas' => null,
                ];

                continue;
            }

            $labels = $inspection['Spec']['Labels'] ?? [];
            $observedHash = is_array($labels) ? ($labels[$unit['config_hash_label']] ?? null) : null;
            $replicas = $inspection['Spec']['Mode']['Replicated']['Replicas'] ?? null;

            $runtimeUnits[$unit['name']] = [
                'config_exists' => true,
                'config_matches' => $observedHash === $unit['config_hash'],
                'service_replicas' => is_numeric($replicas) ? (int) $replicas : null,
            ];
        }

        $runtimeUnitExtras = [];

        if ($backendAvailable) {
            $expectedNames = array_column($expectedUnits, 'name');
            $psResult = $shell->run(
                $node,
                'docker service ls --filter label=orbit.managed=true --filter label=orbit.process='.escapeshellarg($process->name)." --format '{{.Name}}'",
            );

            if ($psResult->successful()) {
                foreach (explode("\n", trim($psResult->stdout)) as $serviceName) {
                    $serviceName = trim($serviceName);

                    if ($serviceName !== '' && ! in_array($serviceName, $expectedNames, true)) {
                        $runtimeUnitExtras[] = $serviceName;
                    }
                }
            }
        }

        return new ProbeSnapshot([
            $process->name => [
                'runtime_backend_available' => $backendAvailable,
                'runtime_backend_exit_code' => $backendExitCode,
                'runtime_backend_output' => $backendOutput,
                'runtime_units' => $runtimeUnits,
                'runtime_unit_extras' => $runtimeUnitExtras,
                'event_notifier' => null,
            ],
        ]);
    }

    private function introspectSystemd(Process $process, Node $node): ProbeSnapshot
    {
        $probe = $this->runtimeBackendProbe()->check($node);
        $spec = $this->expectedSystemdUnitSpecs($process);
        $notifier = [
            'required' => $this->requiresEventNotifier($process),
            'script_hash' => $this->processEventNotifierRenderer()->hash(),
            'gateway_endpoint' => $this->processEventNotifierRenderer()->expectedGatewayEndpoint(),
        ];

        $items = [
            $process->name => [
                'runtime_backend_available' => $probe->available,
                'runtime_backend_exit_code' => $probe->exitCode,
                'runtime_backend_output' => $probe->output,
                'runtime_units' => [],
                'runtime_unit_extras' => [],
                'event_notifier' => null,
            ],
        ];

        if (! $probe->available) {
            return new ProbeSnapshot($items);
        }

        $php = <<<'PHP'
$payload = json_decode(stream_get_contents(STDIN), true);
$units = is_array($payload['units'] ?? null) ? $payload['units'] : [];
$notifier = is_array($payload['event_notifier'] ?? null) ? $payload['event_notifier'] : [];
$expectedNames = [];

foreach ($units as $unit) {
    $name = (string) ($unit['name'] ?? '');
    $expectedNames[$name] = true;
    $path = (string) ($unit['config_path'] ?? '');
    $hash = (string) ($unit['config_hash'] ?? '');
    $restartPolicy = (string) ($unit['restart_policy'] ?? '');
    $environmentLines = is_array($unit['environment_lines'] ?? null) ? $unit['environment_lines'] : [];
    $exists = is_file($path) ? '1' : '0';
    $content = $exists === '1' ? (string) file_get_contents($path) : '';
    $matches = $exists === '1' && hash('sha256', $content) === $hash ? '1' : '0';
    $restartMatches = $exists === '1' && preg_match('/^Restart='.preg_quote($restartPolicy, '/').'$/m', $content) === 1 ? '1' : '0';
    $environmentMatches = $exists === '1' ? '1' : '0';

    foreach ($environmentLines as $environmentLine) {
        if (! is_string($environmentLine) || $environmentLine === '') {
            continue;
        }

        if (preg_match('/^'.preg_quote($environmentLine, '/').'$/m', $content) !== 1) {
            $environmentMatches = '0';
            break;
        }
    }

    printf("%s\t%s\t%s\t%s\t%s\n", $name, $exists, $matches, $restartMatches, $environmentMatches);
}

$notifierPath = '/usr/local/bin/orbit-notify-exit';
$endpointPath = '/etc/orbit/gateway-endpoint';
$notifierExists = is_file($notifierPath) ? '1' : '0';
$notifierExecutable = is_executable($notifierPath) ? '1' : '0';
$notifierMatches = $notifierExists === '1' && hash_file('sha256', $notifierPath) === (string) ($notifier['script_hash'] ?? '') ? '1' : '0';
$expectedEndpoint = (string) ($notifier['gateway_endpoint'] ?? '');
$endpointExists = is_file($endpointPath) ? '1' : '0';
$endpointMatches = $expectedEndpoint !== '' && $endpointExists === '1' && rtrim(trim((string) file_get_contents($endpointPath)), '/') === $expectedEndpoint ? '1' : '0';

printf("__notifier\t%s\t%s\t%s\t%s\t%s\n", $notifierExists, $notifierExecutable, $notifierMatches, $endpointExists, $endpointMatches);

foreach (glob('/etc/systemd/system/orbit_*.service') ?: [] as $path) {
    $name = basename($path, '.service');

    if (! isset($expectedNames[$name])) {
        printf("__extra\t%s\n", $name);
    }
}
PHP;

        $script = 'set -euo pipefail'.PHP_EOL.'php -r '.escapeshellarg($php);

        $result = $this->runtimeBackendProbe()
            ->remoteShell()
            ->run($node, $script, [
                'throw' => true,
                'input' => (string) json_encode([
                    'units' => $spec,
                    'event_notifier' => $notifier,
                ], JSON_THROW_ON_ERROR),
            ]);

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 6);
            $name = $parts[0] ?? '';

            if ($name === '__notifier') {
                if (count($parts) !== 6) {
                    continue;
                }

                [, $scriptExists, $scriptExecutable, $scriptMatches, $endpointExists, $endpointMatches] = $parts;

                $items[$process->name]['event_notifier'] = [
                    'script_exists' => $scriptExists === '1',
                    'script_executable' => $scriptExecutable === '1',
                    'script_matches' => $scriptMatches === '1',
                    'gateway_endpoint_exists' => $endpointExists === '1',
                    'gateway_endpoint_matches' => $endpointMatches === '1',
                ];

                continue;
            }

            if ($name === '__extra') {
                if (count($parts) !== 2) {
                    continue;
                }

                $items[$process->name]['runtime_unit_extras'][] = $parts[1];

                continue;
            }

            if (count($parts) !== 5) {
                continue;
            }

            [$name, $exists, $matches, $restartMatches, $environmentMatches] = $parts;

            $items[$process->name]['runtime_units'][$name] = [
                'config_exists' => $exists === '1',
                'config_matches' => $matches === '1',
                'restart_policy_matches' => $restartMatches === '1',
                'environment_matches' => $environmentMatches === '1',
            ];
        }

        return new ProbeSnapshot($items);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(Process $process, ProbeSnapshot $snapshot): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkRecordCompleteness($process));
        $drift = array_merge($drift, $this->checkOwner($process));
        $drift = array_merge($drift, $this->checkRuntimeContexts($process));
        $drift = array_merge($drift, $this->checkWireGuardSelfRoute($process));
        $drift = array_merge($drift, $this->checkRuntimeBackend($process, $snapshot));
        $drift = array_merge($drift, $this->checkRuntimeUnits($process, $snapshot));
        $drift = array_merge($drift, $this->checkRestartPolicy($process, $snapshot));
        $drift = array_merge($drift, $this->checkRuntimeEnvironment($process, $snapshot));
        $drift = array_merge($drift, $this->checkEventNotifier($process, $snapshot));
        $drift = array_merge($drift, $this->checkRuntimeUnitExtras($process, $snapshot));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(Process $process): array
    {
        $restartPolicy = $process->getRawOriginal('restart_policy');
        $crashNotification = $process->getRawOriginal('crash_notification');

        if (
            ! is_int($process->node_id)
            || ! is_string($process->owner_type)
            || $process->owner_type === ''
            || ! is_int($process->owner_id)
            || ! is_string($process->name)
            || $process->name === ''
            || ! is_string($process->command)
            || trim($process->command) === ''
            || ! is_int($process->sort_order)
            || ! is_string($restartPolicy)
            || ProcessRestartPolicy::tryFrom($restartPolicy) === null
            || ! is_string($crashNotification)
            || ProcessCrashNotification::tryFrom($crashNotification) === null
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Process record for {$process->name} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkOwner(Process $process): array
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            if ($process->owner->isActive()) {
                return [];
            }

            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.owner_node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Process {$process->name} owner node {$process->owner->name} is not active.",
                ),
            ];
        }

        $this->loadProcessApp($process);

        if (! $process->app instanceof App) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.owner_app_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Process {$process->name} points at a missing app.",
                ),
            ];
        }

        if (
            ! $process->app->node instanceof Node
            || ! $process->app->node->isActive()
            || ! app(NodeRoleAssignments::class)->nodeHasActiveAppHostRole($process->app->node)
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.owner_app_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Process {$process->name} owner app {$process->app->name} is not on an active app node.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkWireGuardSelfRoute(Process $process): array
    {
        $node = $this->processNode($process);

        if (! $node instanceof Node) {
            return [];
        }

        $wireGuardAddress = trim((string) $node->wireguard_address);

        if ($wireGuardAddress === '') {
            return [];
        }

        $endpoint = collect($this->serviceEndpoints($process))
            ->first(fn (array $endpoint): bool => $endpoint['host'] === $wireGuardAddress);

        if (! is_array($endpoint)) {
            return [];
        }

        $diagnostic = $this->wireGuardSelfRouteProbe()->probe($node);

        if (($diagnostic['ok'] ?? false) === true) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'process.wireguard_self_route_unavailable',
                kind: DriftKind::Unverifiable,
                summary: "Process {$process->name} endpoint points at node {$node->name}'s own WireGuard address, but local self-route diagnostics are not healthy.",
                detail: [
                    'process' => $process->name,
                    'node' => $node->name,
                    'endpoint' => $endpoint['name'] ?? null,
                    'host' => $endpoint['host'],
                    'port' => $endpoint['port'] ?? null,
                    ...$this->wireGuardSelfRouteDetail($diagnostic),
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeUnitExtras(Process $process, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($process->name);

        if (
            $observed === null
            || ($observed['runtime_backend_available'] ?? null) === false
            || ! is_array($observed['runtime_unit_extras'] ?? null)
        ) {
            return [];
        }

        $isDocker = in_array($this->runtimeFor($process), [ProcessRuntime::Docker, ProcessRuntime::DockerSwarm], true);
        $runtimeUnitPrefix = $this->runtimeUnitPrefix($process);

        return collect($observed['runtime_unit_extras'])
            ->filter(fn (mixed $runtimeUnit): bool => is_string($runtimeUnit) && $runtimeUnit !== '')
            ->filter(fn (string $runtimeUnit): bool => $runtimeUnitPrefix === null || str_starts_with($runtimeUnit, $runtimeUnitPrefix))
            ->map(function (string $runtimeUnit) use ($process, $isDocker): DriftEntry {
                $detail = $this->runtimeUnitDetail($process, [
                    'name' => $runtimeUnit,
                    'config_path' => $runtimeUnit,
                ]);

                if (! $isDocker) {
                    $detail['expected_path'] = "/etc/systemd/system/{$runtimeUnit}.service";
                }

                return new DriftEntry(
                    family: $this->key(),
                    key: 'process.runtime_unit_extra',
                    kind: DriftKind::Extra,
                    summary: "Process runtime unit {$runtimeUnit} has no matching active gateway process intent.",
                    detail: $detail,
                );
            })
            ->values()
            ->all();
    }

    private function runtimeUnitPrefix(Process $process): ?string
    {
        $app = $process->ownerApp();

        if (! $app instanceof App || $app->name === '') {
            return null;
        }

        return "orbit_{$app->name}_";
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkEventNotifier(Process $process, ProbeSnapshot $snapshot): array
    {
        if (! $this->requiresEventNotifier($process)) {
            return [];
        }

        $observed = $snapshot->get($process->name);

        if (
            $observed === null
            || ($observed['runtime_backend_available'] ?? null) === false
            || ! is_array($observed['event_notifier'] ?? null)
        ) {
            return [];
        }

        $notifier = $observed['event_notifier'];

        if (
            ($notifier['script_exists'] ?? null) === false
            || ($notifier['gateway_endpoint_exists'] ?? null) === false
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.event_notifier_missing',
                    kind: DriftKind::Missing,
                    summary: "Process {$process->name} crash event notifier material is missing.",
                    detail: [
                        'script' => $this->processEventNotifierRenderer()->installPath(),
                        'gateway_endpoint' => $this->processEventNotifierRenderer()->gatewayEndpointPath(),
                    ],
                ),
            ];
        }

        if (
            ($notifier['script_executable'] ?? null) === false
            || ($notifier['script_matches'] ?? null) === false
            || ($notifier['gateway_endpoint_matches'] ?? null) === false
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.event_notifier_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Process {$process->name} crash event notifier material differs from gateway intent.",
                    detail: [
                        'script' => $this->processEventNotifierRenderer()->installPath(),
                        'gateway_endpoint' => $this->processEventNotifierRenderer()->gatewayEndpointPath(),
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRestartPolicy(Process $process, ProbeSnapshot $snapshot): array
    {
        if (in_array($this->runtimeFor($process), [ProcessRuntime::Docker, ProcessRuntime::DockerSwarm], true)) {
            return [];
        }

        return $this->checkRuntimeUnitField(
            process: $process,
            snapshot: $snapshot,
            field: 'restart_policy_matches',
            key: 'process.restart_policy_mismatch',
            summary: fn (string $name): string => "Process runtime unit {$name} restart policy differs from gateway process intent.",
        );
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeEnvironment(Process $process, ProbeSnapshot $snapshot): array
    {
        if (in_array($this->runtimeFor($process), [ProcessRuntime::Docker, ProcessRuntime::DockerSwarm], true)) {
            return [];
        }

        return $this->checkRuntimeUnitField(
            process: $process,
            snapshot: $snapshot,
            field: 'environment_matches',
            key: 'process.runtime_environment_mismatch',
            summary: fn (string $name): string => "Process runtime unit {$name} environment differs from gateway process intent.",
        );
    }

    /**
     * @param  callable(string): string  $summary
     * @return list<DriftEntry>
     */
    private function checkRuntimeUnitField(
        Process $process,
        ProbeSnapshot $snapshot,
        string $field,
        string $key,
        callable $summary,
    ): array {
        $observed = $snapshot->get($process->name);

        if (
            $observed === null
            || ($observed['runtime_backend_available'] ?? null) === false
            || ! is_array($observed['runtime_units'] ?? null)
        ) {
            return [];
        }

        $drift = [];

        foreach ($this->expectedRuntimeUnitSpecs($process) as $unit) {
            $name = $unit['name'];
            $runtimeUnit = $observed['runtime_units'][$name] ?? null;

            if (! is_array($runtimeUnit) || ($runtimeUnit['config_exists'] ?? null) === false) {
                continue;
            }

            if (($runtimeUnit[$field] ?? null) === false) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: $key,
                    kind: DriftKind::Divergent,
                    summary: $summary($name),
                    detail: $this->runtimeUnitDetail($process, $unit),
                );
            }
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeUnits(Process $process, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($process->name);

        if (
            $observed === null
            || ($observed['runtime_backend_available'] ?? null) === false
            || ! is_array($observed['runtime_units'] ?? null)
        ) {
            return [];
        }

        $drift = [];

        foreach ($this->expectedRuntimeUnitSpecs($process) as $unit) {
            $name = $unit['name'];
            $runtimeUnit = $observed['runtime_units'][$name] ?? null;

            if (! is_array($runtimeUnit) || ($runtimeUnit['config_exists'] ?? null) === false) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'process.runtime_unit_missing',
                    kind: DriftKind::Missing,
                    summary: "Process runtime unit {$name} is missing.",
                    detail: $this->runtimeUnitDetail($process, $unit),
                );

                continue;
            }

            // For host runtimes, restart and environment drift get their own entries.
            $isMismatch = ($runtimeUnit['config_matches'] ?? null) === false;

            if (! in_array($this->runtimeFor($process), [ProcessRuntime::Docker, ProcessRuntime::DockerSwarm], true)) {
                $isMismatch = $isMismatch
                    && ($runtimeUnit['restart_policy_matches'] ?? null) !== false
                    && ($runtimeUnit['environment_matches'] ?? null) !== false;
            }

            if ($isMismatch) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'process.runtime_unit_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Process runtime unit {$name} differs from gateway process intent.",
                    detail: $this->runtimeUnitDetail($process, $unit),
                );
            }
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeBackend(Process $process, ProbeSnapshot $snapshot): array
    {
        $node = $this->processNode($process);

        if (! $node instanceof Node) {
            return [];
        }

        $observed = $snapshot->get($process->name);

        if ($observed === null) {
            return [];
        }

        if (($observed['runtime_backend_available'] ?? null) === false) {
            $backendName = match ($this->runtimeFor($process)) {
                ProcessRuntime::Docker, ProcessRuntime::DockerSwarm => 'Docker',
                ProcessRuntime::Systemd => 'systemd',
            };

            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.runtime_backend_unavailable',
                    kind: DriftKind::Unverifiable,
                    summary: "{$backendName} runtime backend is unavailable for process {$process->name} on node {$node->name}.",
                    detail: [
                        'process' => $process->name,
                        'node' => $node->name,
                        'runtime' => $this->runtimeFor($process)->value,
                        ...$this->serviceRuntimeDetail($process),
                        'exit_code' => $observed['runtime_backend_exit_code'] ?? null,
                        'output' => $observed['runtime_backend_output'] ?? '',
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeContexts(Process $process): array
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return [];
        }

        $this->loadProcessApp($process, withWorkspaces: true);

        if (! $process->app instanceof App) {
            return [];
        }

        try {
            $runtimeUnits = $this->expectedRuntimeUnits($process);
        } catch (InvalidArgumentException $exception) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.runtime_context_unresolved',
                    kind: DriftKind::Unverifiable,
                    summary: "Process {$process->name} runtime contexts cannot be derived from gateway intent.",
                    detail: [
                        'reason' => $exception->getMessage(),
                    ],
                ),
            ];
        }

        if ($runtimeUnits === []) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.runtime_context_unresolved',
                    kind: DriftKind::Unverifiable,
                    summary: "Process {$process->name} has no derived runtime contexts.",
                ),
            ];
        }

        return [];
    }

    private function processNode(Process $process): ?Node
    {
        $process->loadMissing(['owner', 'node']);

        if ($process->owner instanceof Node) {
            return $process->owner;
        }

        $this->loadProcessApp($process);

        if ($process->app instanceof App && $process->app->node instanceof Node) {
            return $process->app->node;
        }

        return $process->node;
    }

    /**
     * @return list<array{name: string|null, host: string, port: int|null}>
     */
    private function serviceEndpoints(Process $process): array
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $rawEndpoints = [];

        if (is_array($config['endpoint'] ?? null)) {
            $rawEndpoints[] = $config['endpoint'];
        }

        if (is_array($config['endpoints'] ?? null)) {
            foreach ($config['endpoints'] as $endpoint) {
                if (is_array($endpoint)) {
                    $rawEndpoints[] = $endpoint;
                }
            }
        }

        $endpoints = [];

        foreach ($rawEndpoints as $endpoint) {
            $host = is_string($endpoint['host'] ?? null) ? trim($endpoint['host']) : '';

            if ($host === '') {
                continue;
            }

            $name = is_string($endpoint['name'] ?? null) ? trim($endpoint['name']) : null;
            $port = is_numeric($endpoint['port'] ?? null) ? (int) $endpoint['port'] : null;

            $endpoints[] = [
                'name' => $name !== '' ? $name : null,
                'host' => $host,
                'port' => $port,
            ];
        }

        return $endpoints;
    }

    /**
     * @param  array<string, mixed>  $diagnostic
     * @return array<string, mixed>
     */
    private function wireGuardSelfRouteDetail(array $diagnostic): array
    {
        return array_filter([
            'wireguard_address' => $diagnostic['wireguard_address'] ?? null,
            'platform' => $diagnostic['platform'] ?? null,
            'reason' => $diagnostic['reason'] ?? null,
            'message' => $diagnostic['message'] ?? null,
            'command' => $diagnostic['command'] ?? null,
            'exit_code' => $diagnostic['exit_code'] ?? null,
            'output' => $diagnostic['output'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<string>
     */
    private function expectedRuntimeUnits(Process $process): array
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return collect($this->expectedRuntimeUnitSpecs($process))
                ->pluck('name')
                ->values()
                ->all();
        }

        $this->loadProcessApp($process, withWorkspaces: true);

        if (! $process->app instanceof App) {
            return [];
        }

        if ($this->runtimeFor($process) === ProcessRuntime::Docker) {
            return collect($this->runtimeContexts($process))
                ->map(fn (?Workspace $workspace): string => $this->dockerContainerRenderer()->containerName($process->app, $process, $workspace))
                ->values()
                ->all();
        }

        return collect($this->runtimeContexts($process))
            ->map(fn (?Workspace $workspace): string => $this->systemdUnitRenderer()->unitName($process->app, $process, $workspace))
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, config_path: string, config_hash: string, config_hash_label: string, restart_policy: string, environment_lines: list<string>}>
     */
    private function expectedRuntimeUnitSpecs(Process $process): array
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            if ($this->runtimeFor($process) === ProcessRuntime::Docker) {
                return $this->expectedDockerUnitSpecs($process);
            }

            if ($this->runtimeFor($process) === ProcessRuntime::DockerSwarm) {
                return $this->expectedDockerSwarmUnitSpecs($process);
            }

            return $this->expectedSystemdUnitSpecs($process);
        }

        $this->loadProcessApp($process, withWorkspaces: true);

        if (! $process->app instanceof App) {
            return [];
        }

        if ($this->runtimeFor($process) === ProcessRuntime::Docker) {
            return $this->expectedDockerUnitSpecs($process);
        }

        return $this->expectedSystemdUnitSpecs($process);
    }

    /**
     * @return list<array{name: string, config_path: string, config_hash: string, config_hash_label: string, restart_policy: string, environment_lines: list<string>}>
     */
    private function expectedDockerUnitSpecs(Process $process): array
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            $container = $this->dockerContainerRenderer()->render($this->surrogateAppForNode($process->owner), $process);
            $config = is_array($process->runtime_config) ? $process->runtime_config : [];
            $configuredHash = $config['container_spec_hash'] ?? $config['spec_hash'] ?? null;
            $configuredHashLabel = $config['container_spec_hash_label'] ?? null;

            return [[
                'name' => $container->name(),
                'config_path' => $container->name(),
                'config_hash' => is_string($configuredHash) && $configuredHash !== ''
                    ? $configuredHash
                    : $container->specHash(),
                'config_hash_label' => is_string($configuredHashLabel) && $configuredHashLabel !== ''
                    ? $configuredHashLabel
                    : ProcessDockerContainer::SpecHashLabel,
                'restart_policy' => '',
                'environment_lines' => [],
            ]];
        }

        $this->loadProcessApp($process, withWorkspaces: true);

        if (! $process->app instanceof App) {
            return [];
        }

        return collect($this->runtimeContexts($process))
            ->map(function (?Workspace $workspace) use ($process): array {
                $container = $this->dockerContainerRenderer()->render($process->app, $process, $workspace);
                $config = is_array($process->runtime_config) ? $process->runtime_config : [];
                $configuredHash = $config['container_spec_hash'] ?? null;
                $configuredHashLabel = $config['container_spec_hash_label'] ?? null;

                return [
                    'name' => $container->name(),
                    'config_path' => $container->name(),
                    'config_hash' => is_string($configuredHash) && $configuredHash !== ''
                        ? $configuredHash
                        : $container->specHash(),
                    'config_hash_label' => is_string($configuredHashLabel) && $configuredHashLabel !== ''
                        ? $configuredHashLabel
                        : ProcessDockerContainer::SpecHashLabel,
                    'restart_policy' => '',
                    'environment_lines' => [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, config_path: string, config_hash: string, config_hash_label: string, restart_policy: string, environment_lines: list<string>}>
     */
    private function expectedDockerSwarmUnitSpecs(Process $process): array
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $serviceName = $this->optionalConfigString($config, 'service_name') ?? $process->name;
        $configuredHash = $this->optionalConfigString($config, 'spec_hash')
            ?? $this->optionalConfigString($this->stringMap($config['labels'] ?? []), ProcessDockerContainer::SpecHashLabel)
            ?? '';

        return [[
            'name' => $serviceName,
            'config_path' => $serviceName,
            'config_hash' => $configuredHash,
            'config_hash_label' => ProcessDockerContainer::SpecHashLabel,
            'restart_policy' => '',
            'environment_lines' => [],
        ]];
    }

    /**
     * @return list<array{name: string, config_path: string, config_hash: string, config_hash_label: string, restart_policy: string, environment_lines: list<string>}>
     */
    private function expectedSystemdUnitSpecs(Process $process): array
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            $app = $this->surrogateAppForNode($process->owner);
            $runtimeUnit = $this->systemdUnitRenderer()->unitName($app, $process);
            $content = $this->systemdUnitRenderer()->render($process->owner, $app, $process);

            return [[
                'name' => $runtimeUnit,
                'config_path' => $this->systemdUnitRenderer()->unitPath($runtimeUnit),
                'config_hash' => hash('sha256', $content),
                'config_hash_label' => '',
                'restart_policy' => $process->restart_policy->toSystemd(),
                'environment_lines' => $this->environmentLines($content),
            ]];
        }

        $this->loadProcessApp($process, withWorkspaces: true);

        if (! $process->app instanceof App) {
            return [];
        }

        return collect($this->runtimeContexts($process))
            ->map(function (?Workspace $workspace) use ($process): array {
                $node = $process->app->node;

                if (! $node instanceof Node) {
                    return [];
                }

                $runtimeUnit = $this->systemdUnitRenderer()->unitName($process->app, $process, $workspace);
                $content = $this->systemdUnitRenderer()->render($node, $process->app, $process, $workspace);

                return [
                    'name' => $runtimeUnit,
                    'config_path' => $this->systemdUnitRenderer()->unitPath($runtimeUnit),
                    'config_hash' => hash('sha256', $content),
                    'config_hash_label' => '',
                    'restart_policy' => $process->restart_policy->toSystemd(),
                    'environment_lines' => $this->environmentLines($content),
                ];
            })
            ->filter(fn (array $unit): bool => $unit !== [])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function environmentLines(string $content): array
    {
        $lines = [];

        foreach (explode("\n", $content) as $line) {
            if (str_starts_with($line, 'Environment=')) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function requiresEventNotifier(Process $process): bool
    {
        return ProcessCrashNotification::tryFrom((string) $process->getRawOriginal('crash_notification')) === ProcessCrashNotification::AgentIde;
    }

    private function runtimeFor(Process $process): ProcessRuntime
    {
        $raw = $process->getRawOriginal('runtime');

        if (is_string($raw)) {
            return ProcessRuntime::tryFrom($raw) ?? ProcessRuntime::Systemd;
        }

        return $process->runtime ?? ProcessRuntime::Systemd;
    }

    /**
     * @return list<Workspace|null>
     */
    private function runtimeContexts(Process $process): array
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Workspace) {
            return [$process->owner];
        }

        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $containerName = $config['container_name'] ?? null;

        if (is_string($containerName) && trim($containerName) !== '') {
            return [null];
        }

        if (! $process->app instanceof App) {
            return [];
        }

        $process->app->loadMissing('workspaces');

        return [null, ...$process->app->workspaces->all()];
    }

    private function dockerRuntimeUnitExtraCommand(Process $process): string
    {
        $parts = [
            'docker ps -a',
            '--filter label=orbit.managed=true',
        ];

        if ($process->app instanceof App) {
            $parts[] = '--filter label=orbit.app='.escapeshellarg($process->app->name);
        }

        $parts[] = '--filter label=orbit.process='.escapeshellarg($process->name);
        $parts[] = "--format '{{.Names}}'";

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $unit
     * @return array<string, mixed>
     */
    private function runtimeUnitDetail(Process $process, array $unit): array
    {
        return array_filter([
            'process' => $process->name,
            'runtime' => $this->runtimeFor($process)->value,
            'runtime_unit' => $unit['name'] ?? null,
            'expected' => $unit['config_path'] ?? null,
            ...$this->serviceRuntimeDetail($process),
        ], $this->filledDetail(...));
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceRuntimeDetail(Process $process): array
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $definition = $this->optionalConfigString($config, 'definition');

        if ($definition === null) {
            return [];
        }

        return array_filter([
            'definition' => $definition,
            'version_family' => $this->optionalConfigString($config, 'version_family'),
            'version' => $this->optionalConfigString($config, 'version'),
            'service_name' => $this->optionalConfigString($config, 'service_name'),
            'endpoint' => $this->serviceEndpointDetail($config['endpoint'] ?? null),
        ], $this->filledDetail(...));
    }

    private function filledDetail(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return ! is_string($value) || $value !== '';
    }

    /**
     * @return array{name: string|null, host: string, port: int|null}|null
     */
    private function serviceEndpointDetail(mixed $endpoint): ?array
    {
        if (! is_array($endpoint)) {
            return null;
        }

        $host = is_string($endpoint['host'] ?? null) ? trim($endpoint['host']) : '';

        if ($host === '') {
            return null;
        }

        $name = is_string($endpoint['name'] ?? null) ? trim($endpoint['name']) : null;
        $port = is_numeric($endpoint['port'] ?? null) ? (int) $endpoint['port'] : null;

        return [
            'name' => $name !== '' ? $name : null,
            'host' => $host,
            'port' => $port,
        ];
    }

    private function surrogateAppForNode(Node $node): App
    {
        $app = new App([
            'name' => $node->name,
            'path' => ($node->user ?: 'orbit') === 'root'
                ? '/root'
                : '/home/'.($node->user ?: 'orbit'),
            'node_id' => $node->id,
        ]);
        $app->setRelation('node', $node);

        return $app;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function optionalConfigString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_scalar($item)) {
                continue;
            }

            $map[$key] = (string) $item;
        }

        return $map;
    }

    private function loadProcessApp(Process $process, bool $withWorkspaces = false): void
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof App) {
            $process->owner->loadMissing($withWorkspaces ? ['node', 'workspaces'] : ['node']);

            return;
        }

        if ($process->owner instanceof Workspace) {
            $process->owner->loadMissing($withWorkspaces ? ['app.node', 'app.workspaces'] : ['app.node']);
        }
    }

    private function systemdUnitRenderer(): SystemdUnitRenderer
    {
        return $this->systemdUnitRenderer ?? app(SystemdUnitRenderer::class);
    }

    private function runtimeBackendProbe(): RuntimeBackendProbe
    {
        return $this->runtimeBackendProbe ?? app(RuntimeBackendProbe::class);
    }

    private function processEventNotifierRenderer(): ProcessEventNotifierRenderer
    {
        return $this->processEventNotifierRenderer ?? app(ProcessEventNotifierRenderer::class);
    }

    private function dockerContainerRenderer(): ProcessDockerContainerRenderer
    {
        return $this->dockerContainerRenderer ?? app(ProcessDockerContainerRenderer::class);
    }

    private function wireGuardSelfRouteProbe(): NodeWireGuardSelfRouteProbe
    {
        return $this->wireGuardSelfRouteProbe ?? app(NodeWireGuardSelfRouteProbe::class);
    }
}
