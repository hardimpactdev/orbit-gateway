<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Data\Convergence\ManagedFilePlan;
use App\Data\Convergence\ManagedFileProbe;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Convergence\ConvergenceStatus;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Convergence\ManagedFile;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Runtime\OrbitCaddyContainer;
use InvalidArgumentException;
use Throwable;

final readonly class ToolsProbe
{
    private const array ExpectedStates = ['installed', 'absent'];

    public function __construct(
        private ?RemoteShell $remoteShell = null,
        private ?ToolCatalog $catalog = null,
    ) {}

    public function key(): string
    {
        return 'tool';
    }

    public function label(): string
    {
        return 'Tools';
    }

    public function introspect(NodeTool $tool): ProbeSnapshot
    {
        $tool->loadMissing('node');

        if (! $tool->node instanceof Node || $tool->name === '') {
            return new ProbeSnapshot([]);
        }

        $metadata = ($this->catalog ?? app(ToolCatalog::class))->probeMetadata($tool->name);

        if (($metadata['probe'] ?? null) === 'docker_images') {
            return $this->withManagedFileProbes($tool, $this->introspectDockerImages($tool, $metadata));
        }

        $binary = $metadata['binary'] ?? $tool->name;
        $versionCommand = $metadata['version_command'] ?? null;
        $service = $metadata['service'] ?? null;
        $container = $this->expectedContainerName($tool) ?? ($metadata['container'] ?? null);
        $php = <<<'PHP'
$payload = json_decode(stream_get_contents(STDIN), true);
$binary = (string) ($payload['binary'] ?? '');
$versionCommand = (string) ($payload['version_command'] ?? '');
$service = (string) ($payload['service'] ?? '');
$container = (string) ($payload['container'] ?? '');
$path = str_contains($binary, '/')
    ? (is_executable($binary) ? $binary : '')
    : trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));

if ($path === '') {
    exit(1);
}

$version = '';
$state = 'unknown';
$configExists = '';
$configHash = '';
$secretExists = '';
$secretHash = '';
$containerExists = '';
$containerState = '';
$containerSpecHash = '';

if ($versionCommand !== '') {
    $version = trim((string) shell_exec($versionCommand.' 2>/dev/null | head -n 1'));
}

if ($service !== '') {
    $output = [];
    exec('systemctl is-active --quiet '.escapeshellarg($service).' 2>/dev/null', $output, $exitCode);
    $state = $exitCode === 0 ? 'running' : 'stopped';
}

if ($container !== '') {
    $inspectJson = trim((string) shell_exec('docker container inspect --format '.escapeshellarg('{{json .}}').' '.escapeshellarg($container).' 2>/dev/null'));

    if ($inspectJson === '') {
        $containerExists = '0';
        $containerState = 'missing';
    } else {
        $inspect = json_decode($inspectJson, true);
        $containerExists = '1';

        if (is_array($inspect)) {
            $running = $inspect['State']['Running'] ?? false;
            $containerState = $running === true ? 'running' : 'stopped';
            $state = $containerState;
            $labels = is_array($inspect['Config']['Labels'] ?? null) ? $inspect['Config']['Labels'] : [];
            $containerSpecHash = is_string($labels['orbit.caddy.spec_hash'] ?? null) ? $labels['orbit.caddy.spec_hash'] : '';
        }
    }
}

printf("%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n", $path, $version, $state, $configExists, $configHash, $secretExists, $secretHash, $containerExists, $containerState, $containerSpecHash);
PHP;

        $script = 'php -r '.escapeshellarg($php);

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($tool->node, $script, [
            'throw' => false,
            'input' => (string) json_encode([
                'binary' => $binary,
                'version_command' => is_string($versionCommand) ? $versionCommand : '',
                'service' => is_string($service) ? $service : '',
                'container' => is_string($container) ? $container : '',
            ], JSON_THROW_ON_ERROR),
        ]);
        $parts = explode("\t", trim($result->stdout), 10);
        $containerState = ($parts[8] ?? '') !== '' ? $parts[8] : null;

        return $this->withManagedFileProbes($tool, new ProbeSnapshot([
            $tool->name => [
                'installed' => $result->successful(),
                'path' => ($parts[0] ?? '') !== '' ? $parts[0] : null,
                'version' => ($parts[1] ?? '') !== '' ? $parts[1] : null,
                'state' => $containerState ?? (($parts[2] ?? '') !== '' ? $parts[2] : null),
                'config_exists' => ($parts[3] ?? '') !== '' ? $parts[3] === '1' : null,
                'config_hash' => ($parts[4] ?? '') !== '' ? $parts[4] : null,
                'secret_exists' => ($parts[5] ?? '') !== '' ? $parts[5] === '1' : null,
                'secret_hash' => ($parts[6] ?? '') !== '' ? $parts[6] : null,
                'container_exists' => ($parts[7] ?? '') !== '' ? $parts[7] === '1' : null,
                'container_state' => $containerState,
                'container_spec_hash' => ($parts[9] ?? '') !== '' ? $parts[9] : null,
            ],
        ]));
    }

    /**
     * @param  list<NodeTool>  $tools
     * @return array<string, ProbeSnapshot>
     */
    public function introspectMany(array $tools): array
    {
        $snapshots = [];
        $batch = [];
        $batchedTools = [];
        $node = null;

        foreach ($tools as $tool) {
            $tool->loadMissing('node');

            if (! $tool->node instanceof Node || $tool->name === '') {
                $snapshots[$tool->name] = new ProbeSnapshot([]);

                continue;
            }

            $metadata = ($this->catalog ?? app(ToolCatalog::class))->probeMetadata($tool->name);

            if (($metadata['probe'] ?? null) === 'docker_images') {
                $snapshots[$tool->name] = $this->withManagedFileProbes($tool, $this->introspectDockerImages($tool, $metadata));

                continue;
            }

            if ($node !== null && $node->id !== $tool->node->id) {
                $snapshots[$tool->name] = $this->introspect($tool);

                continue;
            }

            $node = $tool->node;
            $batch[$tool->name] = [
                'binary' => $metadata['binary'] ?? $tool->name,
                'version_command' => is_string($metadata['version_command'] ?? null) ? $metadata['version_command'] : '',
                'service' => is_string($metadata['service'] ?? null) ? $metadata['service'] : '',
                'container' => $this->expectedContainerName($tool) ?? (is_string($metadata['container'] ?? null) ? $metadata['container'] : ''),
            ];
            $batchedTools[$tool->name] = $tool;
        }

        if ($batch === [] || ! $node instanceof Node) {
            return $snapshots;
        }

        $php = <<<'PHP'
$payload = json_decode(stream_get_contents(STDIN), true);
$tools = is_array($payload['tools'] ?? null) ? $payload['tools'] : [];

foreach ($tools as $name => $tool) {
    if (! is_string($name) || ! is_array($tool)) {
        continue;
    }

    $binary = (string) ($tool['binary'] ?? '');
    $versionCommand = (string) ($tool['version_command'] ?? '');
    $service = (string) ($tool['service'] ?? '');
    $container = (string) ($tool['container'] ?? '');
    $path = str_contains($binary, '/')
        ? (is_executable($binary) ? $binary : '')
        : trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));

    $version = '';
    $state = 'unknown';
    $containerExists = null;
    $containerState = null;
    $containerSpecHash = null;

    if ($path !== '' && $versionCommand !== '') {
        $version = trim((string) shell_exec($versionCommand.' 2>/dev/null | head -n 1'));
    }

    if ($path !== '' && $service !== '') {
        $output = [];
        exec('systemctl is-active --quiet '.escapeshellarg($service).' 2>/dev/null', $output, $exitCode);
        $state = $exitCode === 0 ? 'running' : 'stopped';
    }

    if ($path !== '' && $container !== '') {
        $inspectJson = trim((string) shell_exec('docker container inspect --format '.escapeshellarg('{{json .}}').' '.escapeshellarg($container).' 2>/dev/null'));

        if ($inspectJson === '') {
            $containerExists = false;
            $containerState = 'missing';
        } else {
            $inspect = json_decode($inspectJson, true);
            $containerExists = true;

            if (is_array($inspect)) {
                $running = $inspect['State']['Running'] ?? false;
                $containerState = $running === true ? 'running' : 'stopped';
                $state = $containerState;
                $labels = is_array($inspect['Config']['Labels'] ?? null) ? $inspect['Config']['Labels'] : [];
                $containerSpecHash = is_string($labels['orbit.caddy.spec_hash'] ?? null) ? $labels['orbit.caddy.spec_hash'] : null;
            }
        }
    }

    echo json_encode([
        'name' => $name,
        'installed' => $path !== '',
        'path' => $path !== '' ? $path : null,
        'version' => $version !== '' ? $version : null,
        'state' => $containerState ?? ($state !== '' ? $state : null),
        'container_exists' => $containerExists,
        'container_state' => $containerState,
        'container_spec_hash' => $containerSpecHash,
    ], JSON_THROW_ON_ERROR)."\n";
}
PHP;

        $script = 'php -r '.escapeshellarg($php);
        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($node, $script, [
            'throw' => false,
            'input' => (string) json_encode(['tools' => $batch], JSON_THROW_ON_ERROR),
        ]);

        if (! $result->successful()) {
            foreach (array_keys($batch) as $toolName) {
                $snapshots[$toolName] = new ProbeSnapshot([]);
            }

            return $snapshots;
        }

        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $payload = json_decode($line, associative: true);

            if (! is_array($payload) || ! is_string($payload['name'] ?? null)) {
                continue;
            }

            $name = $payload['name'];
            unset($payload['name']);

            $snapshots[$name] = new ProbeSnapshot([$name => $payload]);
        }

        foreach (array_keys($batch) as $toolName) {
            $snapshots[$toolName] ??= new ProbeSnapshot([]);
        }

        foreach ($batchedTools as $toolName => $tool) {
            $snapshots[$toolName] = $this->withManagedFileProbes($tool, $snapshots[$toolName] ?? new ProbeSnapshot([]));
        }

        return $snapshots;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function introspectDockerImages(NodeTool $tool, array $metadata): ProbeSnapshot
    {
        $images = is_array($metadata['images'] ?? null)
            ? array_values(array_filter($metadata['images'], is_string(...)))
            : [];
        $script = <<<'BASH'
found=0
while IFS= read -r image; do
    [ -n "$image" ] || continue
    if docker image inspect "$image" >/dev/null 2>&1; then
        printf '%s\n' "$image"
        found=1
    fi
done

[ "$found" -eq 1 ]
BASH;

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($tool->node, $script, [
            'throw' => false,
            'input' => implode("\n", $images)."\n",
        ]);
        $catalog = app(PhpRuntimeCatalog::class);
        $observedImages = array_values(array_filter(
            preg_split('/\R/', trim($result->stdout)) ?: [],
            fn (string $image): bool => in_array($image, $images, true) && $catalog->isApprovedImage($image),
        ));
        $versions = array_values(array_map(
            $catalog->versionForImage(...),
            $observedImages,
        ));

        return new ProbeSnapshot([
            $tool->name => [
                'installed' => $result->successful() && $observedImages !== [],
                'path' => null,
                'version' => $versions[0] ?? null,
                'state' => null,
                'images' => $observedImages,
                'versions' => $versions,
                'config_exists' => null,
                'config_hash' => null,
                'secret_exists' => null,
                'secret_hash' => null,
            ],
        ]);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(NodeTool $tool, ProbeSnapshot $snapshot, bool $allowProvisioning = false): array
    {
        return [
            ...$this->checkRecordCompleteness($tool),
            ...$this->checkNodeEligibility($tool, $allowProvisioning),
            ...$this->checkDefinition($tool),
            ...$this->checkCapabilityPresence($tool, $snapshot),
            ...$this->checkContainerState($tool, $snapshot),
            ...$this->checkVersionState($tool, $snapshot),
            ...$this->checkConfigState($tool, $snapshot),
            ...$this->checkCredentialState($tool, $snapshot),
            ...$this->checkAgentRoute($tool),
            ...$this->checkAgentCredentials($tool),
            ...$this->checkAgentUser($tool),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(NodeTool $tool): array
    {
        $issues = [];

        if (
            ! is_int($tool->node_id)
            || $tool->name === ''
            || ! in_array($tool->expected_state, self::ExpectedStates, true)
        ) {
            $issues[] = new DriftEntry(
                family: $this->key(),
                key: 'tool.record_incomplete',
                kind: DriftKind::Missing,
                summary: "Tool record {$tool->name} is missing required fields.",
            );
        }

        if (($reason = $this->managedConfigIntentError($tool)) !== null) {
            $issues[] = new DriftEntry(
                family: $this->key(),
                key: 'tool.record_incomplete',
                kind: DriftKind::Missing,
                summary: "Tool {$tool->name} managed configuration intent is incomplete.",
                detail: [
                    'tool' => $tool->name,
                    'field' => 'managed_config',
                    'reason' => $reason,
                ],
            );
        }

        if (($reason = $this->managedSecretIntentError($tool)) !== null) {
            $issues[] = new DriftEntry(
                family: $this->key(),
                key: 'tool.record_incomplete',
                kind: DriftKind::Missing,
                summary: "Tool {$tool->name} managed credential intent is incomplete.",
                detail: [
                    'tool' => $tool->name,
                    'field' => 'managed_secret',
                    'reason' => $reason,
                ],
            );
        }

        return $issues;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkNodeEligibility(NodeTool $tool, bool $allowProvisioning): array
    {
        $tool->loadMissing('node');

        if (! $tool->node instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} points at a missing node.",
                ),
            ];
        }

        $allowedStatus = $tool->node->isActive()
            || ($allowProvisioning && $tool->node->isProvisioning());

        if (! $allowedStatus || ! $this->isToolNode($tool)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} targets node {$tool->node->name}, which is not an active managed-tool node.",
                    detail: [
                        'node' => $tool->node->name,
                        'role' => $tool->node->displayRole(),
                        'status' => $tool->node->status->value,
                    ],
                ),
            ];
        }

        return [];
    }

    private function isToolNode(NodeTool $tool): bool
    {
        $assignments = app(NodeRoleAssignments::class);

        if ($tool->name === 'caddy') {
            return $assignments->nodeHostsOrbitCaddy($tool->node);
        }

        if ($tool->name === 'node-exporter') {
            return $assignments->nodeCanHostMetricsExporter($tool->node);
        }

        return $assignments->nodeCanHostManagedTools($tool->node);
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDefinition(NodeTool $tool): array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if ($tool->name !== '' && $catalog->supports($tool->name)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.definition_missing',
                kind: DriftKind::Missing,
                summary: "Tool {$tool->name} is not present in the Orbit tool catalog.",
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCapabilityPresence(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        if ($tool->expected_state === 'absent') {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) === true) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.capability_missing',
                kind: DriftKind::Missing,
                summary: "Tool {$tool->name} is missing on the target node.",
                detail: [
                    'tool' => $tool->name,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkVersionState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        if ($tool->expected_version === null || $tool->expected_version === '') {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        $version = is_string($observed['version'] ?? null) ? $observed['version'] : null;

        if ($version === null || str_starts_with($version, (string) $tool->expected_version)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.version_mismatch',
                kind: DriftKind::Divergent,
                summary: "Tool {$tool->name} version differs from gateway intent.",
                detail: [
                    'tool' => $tool->name,
                    'expected_version' => $tool->expected_version,
                    'observed_version' => $version,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkContainerState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        $expectedHash = $this->expectedContainerSpecHash($tool);

        if ($expectedHash === null) {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        $containerName = $this->expectedContainerName($tool) ?? $tool->name;

        if (($observed['container_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.container_missing',
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} container {$containerName} is missing.",
                    detail: [
                        'tool' => $tool->name,
                        'container' => $containerName,
                    ],
                ),
            ];
        }

        if (($observed['container_exists'] ?? null) !== true) {
            return [];
        }

        $observedHash = is_string($observed['container_spec_hash'] ?? null)
            ? $observed['container_spec_hash']
            : null;

        if ($observedHash !== null && hash_equals($expectedHash, $observedHash)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.container_spec_mismatch',
                kind: DriftKind::Divergent,
                summary: "Tool {$tool->name} container {$containerName} differs from gateway intent.",
                detail: [
                    'tool' => $tool->name,
                    'container' => $containerName,
                    'expected_hash' => $expectedHash,
                    'observed_hash' => $observedHash,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkConfigState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        $file = $this->managedConfigFile($tool);

        if (! $file instanceof ManagedFile) {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        $probe = $this->managedFileProbeFromSnapshot($observed, 'config');

        if (! $probe instanceof ManagedFileProbe) {
            return [];
        }

        return $this->managedFileDriftFromPlan(
            tool: $tool,
            plan: $file->plan($probe),
            probe: $probe,
            missingKey: 'tool.config_missing',
            mismatchKey: 'tool.config_mismatch',
            probeFailedKey: 'tool.config_probe_failed',
            label: 'managed configuration',
        );
    }

    private function managedConfigFile(NodeTool $tool): ?ManagedFile
    {
        $intent = $this->managedConfigIntent($tool);

        if ($intent === null) {
            return null;
        }

        try {
            return ManagedFile::fromIntent($intent);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function managedConfigIntentError(NodeTool $tool): ?string
    {
        $intent = $this->managedConfigIntent($tool);

        return $intent === null ? null : $this->managedFileIntentError($intent);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function managedConfigIntent(NodeTool $tool): ?array
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $managedConfig = $config['managed_config'] ?? null;

        return is_array($managedConfig) ? $managedConfig : null;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCredentialState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        $file = $this->managedSecretFile($tool);

        if (! $file instanceof ManagedFile) {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        $probe = $this->managedFileProbeFromSnapshot($observed, 'secret');

        if (! $probe instanceof ManagedFileProbe) {
            return [];
        }

        return $this->managedFileDriftFromPlan(
            tool: $tool,
            plan: $file->plan($probe),
            probe: $probe,
            missingKey: 'tool.credentials_missing',
            mismatchKey: 'tool.credentials_mismatch',
            probeFailedKey: 'tool.credentials_probe_failed',
            label: 'managed credential material',
        );
    }

    private function managedSecretFile(NodeTool $tool): ?ManagedFile
    {
        $intent = $this->managedSecretIntent($tool);

        if ($intent === null) {
            return null;
        }

        try {
            return ManagedFile::fromIntent(
                intent: $intent,
                defaultMode: '0600',
                defaultDirectoryMode: '0700',
                sensitive: true,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function managedSecretIntentError(NodeTool $tool): ?string
    {
        $intent = $this->managedSecretIntent($tool);

        return $intent === null
            ? null
            : $this->managedFileIntentError($intent, defaultMode: '0600', defaultDirectoryMode: '0700', sensitive: true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function managedSecretIntent(NodeTool $tool): ?array
    {
        $credentials = is_array($tool->credentials) ? $tool->credentials : [];
        $managedSecret = $credentials['managed_secret'] ?? null;

        return is_array($managedSecret) ? $managedSecret : null;
    }

    private function expectedContainerSpecHash(NodeTool $tool): ?string
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $container = is_array($config['container'] ?? null) ? $config['container'] : null;

        if ($container === null) {
            return null;
        }

        if ($tool->name === 'caddy') {
            return OrbitCaddyContainer::fromConfig($container)->specHash();
        }

        $hash = $container['spec_hash'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    private function expectedContainerName(NodeTool $tool): ?string
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $container = is_array($config['container'] ?? null) ? $config['container'] : [];
        $name = $container['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function withManagedFileProbes(NodeTool $tool, ProbeSnapshot $snapshot): ProbeSnapshot
    {
        $tool->loadMissing('node');
        $observed = $snapshot->get($tool->name);

        if (! $tool->node instanceof Node || ($observed['installed'] ?? null) !== true) {
            return $snapshot;
        }

        $remoteShell = $this->remoteShell ?? app(RemoteShell::class);

        if (($file = $this->managedConfigFile($tool)) instanceof ManagedFile) {
            $observed = [
                ...$observed,
                ...$this->managedFileProbeSnapshot('config', $file->probe($tool->node, $remoteShell)),
            ];
        }

        if (($file = $this->managedSecretFile($tool)) instanceof ManagedFile) {
            $observed = [
                ...$observed,
                ...$this->managedFileProbeSnapshot('secret', $file->probe($tool->node, $remoteShell)),
            ];
        }

        return new ProbeSnapshot([
            ...$snapshot->items,
            $tool->name => $observed,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function managedFileProbeSnapshot(string $prefix, ManagedFileProbe $probe): array
    {
        return [
            "{$prefix}_probe_reachable" => $probe->reachable,
            "{$prefix}_exists" => $probe->exists,
            "{$prefix}_hash" => $probe->hash,
            "{$prefix}_mode" => $probe->mode,
            "{$prefix}_probe_error" => $probe->error,
        ];
    }

    /**
     * @param  array<string, mixed>  $observed
     */
    private function managedFileProbeFromSnapshot(array $observed, string $prefix): ?ManagedFileProbe
    {
        $reachable = $observed["{$prefix}_probe_reachable"] ?? null;

        if (! is_bool($reachable)) {
            return null;
        }

        return new ManagedFileProbe(
            reachable: $reachable,
            exists: ($observed["{$prefix}_exists"] ?? null) === true,
            hash: is_string($observed["{$prefix}_hash"] ?? null) ? $observed["{$prefix}_hash"] : null,
            mode: is_string($observed["{$prefix}_mode"] ?? null) ? $observed["{$prefix}_mode"] : null,
            error: is_string($observed["{$prefix}_probe_error"] ?? null) ? $observed["{$prefix}_probe_error"] : null,
        );
    }

    /**
     * @return list<DriftEntry>
     */
    private function managedFileDriftFromPlan(
        NodeTool $tool,
        ManagedFilePlan $plan,
        ManagedFileProbe $probe,
        string $missingKey,
        string $mismatchKey,
        string $probeFailedKey,
        string $label,
    ): array {
        if ($plan->status === ConvergenceStatus::Ok) {
            return [];
        }

        $detail = [
            'tool' => $tool->name,
            ...$plan->details,
        ];

        if ($plan->status === ConvergenceStatus::Unreachable) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: $probeFailedKey,
                    kind: DriftKind::Unverifiable,
                    summary: "Tool {$tool->name} {$label} could not be inspected.",
                    detail: $detail,
                ),
            ];
        }

        if ($plan->status !== ConvergenceStatus::Changed) {
            return [];
        }

        if (! $probe->exists) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: $missingKey,
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} {$label} is missing.",
                    detail: $detail,
                ),
            ];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: $mismatchKey,
                kind: DriftKind::Divergent,
                summary: "Tool {$tool->name} {$label} differs from gateway intent.",
                detail: $detail,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function managedFileIntentError(
        array $intent,
        string $defaultMode = '0644',
        string $defaultDirectoryMode = '0755',
        bool $sensitive = false,
    ): ?string {
        try {
            ManagedFile::fromIntent(
                intent: $intent,
                defaultMode: $defaultMode,
                defaultDirectoryMode: $defaultDirectoryMode,
                sensitive: $sensitive,
            );

            return null;
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAgentRoute(NodeTool $tool): array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if ($catalog->category($tool->name) !== 'agent') {
            return [];
        }

        $tool->loadMissing('node');

        if (! $tool->node instanceof Node) {
            return [];
        }

        $tld = $this->agentTldForNode($tool->node);

        if ($tld === null) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.agent_route_missing',
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} cannot verify proxy route because the node has no active agent role TLD.",
                    detail: [
                        'tool' => $tool->name,
                        'node' => $tool->node->name,
                    ],
                ),
            ];
        }

        $domain = "{$tool->name}.{$tld}";
        $route = ProxyRoute::query()
            ->where('node_id', $tool->node->id)
            ->where('domain', $domain)
            ->where('owner_type', 'tool')
            ->first();

        if (! $route instanceof ProxyRoute) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.agent_route_missing',
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} is missing the internal proxy route under the agent role TLD.",
                    detail: [
                        'tool' => $tool->name,
                        'node' => $tool->node->name,
                        'domain' => $domain,
                    ],
                ),
            ];
        }

        $routeOwner = is_array($route->config) ? ($route->config['owner_name'] ?? null) : null;

        if ($routeOwner !== $tool->name) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.agent_route_missing',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} proxy route is owned by a different tool.",
                    detail: [
                        'tool' => $tool->name,
                        'node' => $tool->node->name,
                        'domain' => $domain,
                        'route_owner' => $routeOwner,
                    ],
                ),
            ];
        }

        $expectedConfig = $this->agentProxyRouteConfig($tool->name);
        $expectedHash = $this->agentProxyRouteSourceHash($tool->node, $domain, $expectedConfig);
        $routeConfig = is_array($route->config) ? $route->config : [];

        if (
            $route->kind !== 'proxy'
            || ($routeConfig['target']['type'] ?? null) !== ($expectedConfig['target']['type'] ?? null)
            || ($routeConfig['target']['value'] ?? null) !== ($expectedConfig['target']['value'] ?? null)
            || ($routeConfig['upstream'] ?? null) !== ($expectedConfig['upstream'] ?? null)
            || $route->source_hash !== $expectedHash
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.agent_route_missing',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} proxy route does not match Orbit's managed agent route shape.",
                    detail: [
                        'tool' => $tool->name,
                        'node' => $tool->node->name,
                        'domain' => $domain,
                        'expected_kind' => 'proxy',
                        'observed_kind' => $route->kind,
                        'expected_upstream' => $expectedConfig['upstream'],
                        'observed_upstream' => $routeConfig['target']['value'] ?? $routeConfig['upstream'] ?? null,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return array{target: array{type: string, value: string}, upstream: string, owner_name: string}
     */
    private function agentProxyRouteConfig(string $tool): array
    {
        $upstream = 'http://'.ProxyRouteRenderer::HostLoopbackHostname.':8080';

        return [
            'target' => ['type' => 'upstream', 'value' => $upstream],
            'upstream' => $upstream,
            'owner_name' => $tool,
        ];
    }

    /**
     * @param  array{target: array{type: string, value: string}, upstream: string, owner_name: string}  $config
     */
    private function agentProxyRouteSourceHash(Node $node, string $domain, array $config): string
    {
        return app(ProxyRouteRenderer::class)->sourceHash(new ProxyRoute([
            'node_id' => $node->id,
            'domain' => $domain,
            'kind' => 'proxy',
            'owner_type' => 'tool',
            'config' => $config,
        ]));
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAgentCredentials(NodeTool $tool): array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if (! $catalog->hasCapability($tool->name, 'credentials')) {
            return [];
        }

        if ($catalog->category($tool->name) !== 'agent') {
            return [];
        }

        $credentials = is_array($tool->credentials) ? $tool->credentials : [];

        if ($credentials === []) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.agent_credentials_missing',
                    kind: DriftKind::Missing,
                    summary: "Tool {$tool->name} declares credentials but no managed credential metadata is present.",
                    detail: [
                        'tool' => $tool->name,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAgentUser(NodeTool $tool): array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if ($catalog->category($tool->name) !== 'agent') {
            return [];
        }

        $tool->loadMissing('node');

        if (! $tool->node instanceof Node) {
            return [];
        }

        if (! ($this->remoteShell instanceof RemoteShell)) {
            return [];
        }

        try {
            $result = $this->remoteShell->run($tool->node, 'id -u agent >/dev/null 2>&1', [
                'timeout' => 10,
                'throw' => false,
            ]);

            if (! $result->successful()) {
                return [
                    new DriftEntry(
                        family: $this->key(),
                        key: 'tool.agent_user_missing',
                        kind: DriftKind::Missing,
                        summary: "Tool {$tool->name} is installed on a node whose agent runtime user is absent.",
                        detail: [
                            'tool' => $tool->name,
                            'node' => $tool->node->name,
                        ],
                    ),
                ];
            }
        } catch (Throwable) {
            return [];
        }

        return [];
    }

    private function agentTldForNode(Node $node): ?string
    {
        $assignment = app(NodeRoleAssignments::class)->activeAssignment($node, NodeRoleName::Agent->value);

        if ($assignment instanceof NodeRoleAssignment) {
            $settings = $assignment->settings ?? [];
            $tld = is_array($settings) ? ($settings['tld'] ?? null) : null;

            if (is_string($tld) && trim($tld) !== '') {
                return trim($tld);
            }
        }

        if (is_string($node->tld) && trim($node->tld) !== '') {
            return trim($node->tld);
        }

        return null;
    }
}
