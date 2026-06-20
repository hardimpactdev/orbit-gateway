<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use InvalidArgumentException;
use Throwable;

final readonly class WebSocketDoctorProbe
{
    public function __construct(
        private RemoteShell $remoteShell,
        private WebSocketRuntimeContainerRenderer $runtimeRenderer,
        private WebSocketBackendName $backendName,
        private WebSocketRedisResolver $redisResolver,
    ) {}

    /**
     * @return list<DriftEntry>
     */
    public function nodeDrift(Node $node, NodeRoleAssignment $assignment): array
    {
        return [
            ...$this->checkBackendCertificate($node, $assignment),
            ...$this->checkRuntimeBind($node, $assignment),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    public function toolDrift(Node $node, NodeRoleAssignment $assignment): array
    {
        $drift = [];
        $runtime = $this->runtimeProbe($node);

        if (! $runtime->successful()) {
            return [
                $this->runtimeUnavailableEntry(
                    node: $node,
                    assignment: $assignment,
                    detail: [
                        'reason' => 'runtime_probe_failed',
                        'exit_code' => $runtime->exitCode,
                        'stderr' => trim($runtime->stderr),
                    ],
                    kind: DriftKind::Unverifiable,
                ),
            ];
        }

        $runtimeState = $this->parseKeyValueOutput($runtime->stdout);

        if (($runtimeState['exists'] ?? null) !== '1' || ($runtimeState['running'] ?? null) !== 'true') {
            $drift[] = $this->runtimeUnavailableEntry(
                node: $node,
                assignment: $assignment,
                detail: [
                    'reason' => (($runtimeState['exists'] ?? null) !== '1') ? 'runtime_missing' : 'runtime_stopped',
                    'container' => $this->runtimeRenderer->containerName($node),
                    'exists' => $runtimeState['exists'] ?? null,
                    'running' => $runtimeState['running'] ?? null,
                ],
                kind: (($runtimeState['exists'] ?? null) !== '1') ? DriftKind::Missing : DriftKind::Divergent,
            );
        }

        $settings = $this->settingsFrom($assignment);

        if (! $settings instanceof WebSocketRoleSettings) {
            return [
                ...$drift,
                $this->redisUnavailableEntry(
                    node: $node,
                    assignment: $assignment,
                    detail: [
                        'reason' => 'role_settings_invalid',
                    ],
                    kind: DriftKind::Divergent,
                ),
            ];
        }

        $redisNode = $this->redisResolver->usableRedisNode($settings->redisNodeId);

        if (! $redisNode instanceof Node) {
            return [
                ...$drift,
                $this->redisUnavailableEntry(
                    node: $node,
                    assignment: $assignment,
                    detail: [
                        'reason' => 'redis_node_unavailable',
                        'redis_node_id' => $settings->redisNodeId,
                    ],
                    kind: DriftKind::Divergent,
                ),
            ];
        }

        if (($runtimeState['exists'] ?? null) !== '1' || ($runtimeState['running'] ?? null) !== 'true') {
            return $drift;
        }

        $redis = $this->redisProbe($node);

        if ($redis->successful()) {
            return $drift;
        }

        return [
            ...$drift,
            $this->redisUnavailableEntry(
                node: $node,
                assignment: $assignment,
                detail: [
                    'reason' => 'redis_probe_failed',
                    'redis_node' => $redisNode->name,
                    'redis_node_id' => $redisNode->id,
                    'exit_code' => $redis->exitCode,
                    'stderr' => trim($redis->stderr),
                ],
                kind: DriftKind::Unverifiable,
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkBackendCertificate(Node $node, NodeRoleAssignment $assignment): array
    {
        $backendName = $this->backendName->forNode($node);
        $paths = $this->certificatePathsFor($backendName);
        $probe = $this->backendCertificateProbe($node, $backendName, $paths['cert'], $paths['key']);

        if (! $probe->successful()) {
            return [
                new DriftEntry(
                    family: 'node',
                    key: 'node.websocket.backend_cert_missing',
                    kind: DriftKind::Unverifiable,
                    summary: "WebSocket backend certificate material could not be verified on node {$node->name}.",
                    detail: [
                        'role' => $assignment->role,
                        'backend' => $backendName,
                        'cert_path' => $paths['cert'],
                        'key_path' => $paths['key'],
                        'exit_code' => $probe->exitCode,
                        'stderr' => trim($probe->stderr),
                    ],
                ),
            ];
        }

        $state = $this->parseKeyValueOutput($probe->stdout);

        if (($state['cert_exists'] ?? null) === '1' && ($state['key_exists'] ?? null) === '1' && ($state['cert_matches'] ?? null) === '1') {
            return [];
        }

        $kind = match (true) {
            ($state['cert_exists'] ?? null) !== '1',
            ($state['key_exists'] ?? null) !== '1' => DriftKind::Missing,
            ($state['cert_matches'] ?? null) === '' => DriftKind::Unverifiable,
            default => DriftKind::Divergent,
        };

        return [
            new DriftEntry(
                family: 'node',
                key: 'node.websocket.backend_cert_missing',
                kind: $kind,
                summary: "WebSocket backend certificate material for {$backendName} is missing or mismatched on node {$node->name}.",
                detail: [
                    'role' => $assignment->role,
                    'backend' => $backendName,
                    'cert_path' => $paths['cert'],
                    'key_path' => $paths['key'],
                    'cert_exists' => $state['cert_exists'] ?? null,
                    'key_exists' => $state['key_exists'] ?? null,
                    'cert_matches' => $state['cert_matches'] ?? null,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeBind(Node $node, NodeRoleAssignment $assignment): array
    {
        $probe = $this->runtimeProbe($node);

        if (! $probe->successful()) {
            return [
                new DriftEntry(
                    family: 'node',
                    key: 'node.websocket.bind_public_interface',
                    kind: DriftKind::Unverifiable,
                    summary: "WebSocket runtime bind posture could not be verified on node {$node->name}.",
                    detail: [
                        'role' => $assignment->role,
                        'container' => $this->runtimeRenderer->containerName($node),
                        'exit_code' => $probe->exitCode,
                        'stderr' => trim($probe->stderr),
                    ],
                ),
            ];
        }

        $state = $this->parseKeyValueOutput($probe->stdout);
        $expectedBind = trim((string) $node->wireguard_address);
        $observedBind = $this->observedBindAddress($state);

        if ($observedBind === null || $observedBind === $expectedBind) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'node',
                key: 'node.websocket.bind_public_interface',
                kind: DriftKind::Divergent,
                summary: "WebSocket runtime on node {$node->name} is not bound to its WireGuard address.",
                detail: [
                    'role' => $assignment->role,
                    'container' => $this->runtimeRenderer->containerName($node),
                    'expected_bind' => $expectedBind,
                    'observed_bind' => $observedBind,
                    'env_host' => $state['env_host'] ?? null,
                    'cmd_host' => $state['cmd_host'] ?? null,
                ],
            ),
        ];
    }

    /**
     * @param  array<string, string>  $state
     */
    private function observedBindAddress(array $state): ?string
    {
        $envHost = trim($state['env_host'] ?? '');

        if ($envHost !== '') {
            return $envHost;
        }

        $cmdHost = trim($state['cmd_host'] ?? '');

        return $cmdHost !== '' ? $cmdHost : null;
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function certificatePathsFor(string $backendName): array
    {
        return [
            'cert' => WebSocketCertificateInstaller::CertificateDirectory."/{$backendName}.crt",
            'key' => WebSocketCertificateInstaller::CertificateDirectory."/{$backendName}.key",
        ];
    }

    private function runtimeProbe(Node $node): RemoteShellResult
    {
        $container = $this->runtimeRenderer->containerName($node);

        return $this->runProbe($node, sprintf(
            <<<'SH'
# orbit-websocket-doctor:runtime-probe
set -eu
container=%s

if ! docker container inspect "$container" >/dev/null 2>&1; then
    printf 'exists=0\nrunning=false\nenv_host=\ncmd_host=\n'
    exit 0
fi

running="$(docker container inspect --format '{{.State.Running}}' "$container" 2>/dev/null || printf 'false')"
env_host="$(docker container inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$container" 2>/dev/null | awk -F= '$1 == "REVERB_SERVER_HOST" {print $2; exit}')"
cmd="$(docker container inspect --format '{{range .Config.Cmd}}{{print . " "}}{{end}}' "$container" 2>/dev/null || true)"
cmd_host="$(printf '%%s' "$cmd" | sed -n 's/.*--host=\([^ ]*\).*/\1/p' | head -n 1)"

printf 'exists=1\nrunning=%%s\nenv_host=%%s\ncmd_host=%%s\n' "$running" "$env_host" "$cmd_host"
SH,
            escapeshellarg($container),
        ), 'websocket-runtime-doctor-probe');
    }

    private function backendCertificateProbe(Node $node, string $backendName, string $certPath, string $keyPath): RemoteShellResult
    {
        return $this->runProbe($node, sprintf(
            <<<'SH'
# orbit-websocket-doctor:backend-cert-probe
set -u
backend=%s
cert=%s
key=%s
cert_exists=0
key_exists=0
cert_matches=

if sudo test -f "$cert"; then
    cert_exists=1
fi

if sudo test -f "$key"; then
    key_exists=1
fi

if [ "$cert_exists" = "1" ] && command -v openssl >/dev/null 2>&1; then
    cert_text="$(sudo openssl x509 -in "$cert" -noout -subject -ext subjectAltName 2>/dev/null || true)"

    case "$cert_text" in
        *"$backend"*) cert_matches=1 ;;
        *) cert_matches=0 ;;
    esac
fi

printf 'cert_exists=%%s\nkey_exists=%%s\ncert_matches=%%s\n' "$cert_exists" "$key_exists" "$cert_matches"
SH,
            escapeshellarg($backendName),
            escapeshellarg($certPath),
            escapeshellarg($keyPath),
        ), 'websocket-backend-cert-doctor-probe');
    }

    private function redisProbe(Node $node): RemoteShellResult
    {
        // RemoteHostExecutor may inspect/control Docker and exec inside managed
        // workload containers. The PHP below is fed to the WebSocket runtime
        // container, not executed on the host substrate.
        $php = <<<'PHP'
<?php

$host = getenv('REDIS_HOST') ?: 'redis.orbit';
$port = (int) (getenv('REDIS_PORT') ?: 6379);
$errno = 0;
$errstr = '';
$socket = @fsockopen($host, $port, $errno, $errstr, 2);

if (! $socket) {
    fwrite(STDERR, $errstr !== '' ? $errstr : 'redis unavailable');
    exit(1);
}

fclose($socket);
PHP;

        return $this->runProbe($node, sprintf(
            <<<'SH'
# orbit-websocket-doctor:redis-probe
set -eu
container=%s
docker exec -i "$container" php <<'PHP'
%s
PHP
SH,
            escapeshellarg($this->runtimeRenderer->containerName($node)),
            $php,
        ), 'websocket-redis-doctor-probe');
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

    private function settingsFrom(NodeRoleAssignment $assignment): ?WebSocketRoleSettings
    {
        try {
            return WebSocketRoleSettings::fromArray(is_array($assignment->settings) ? $assignment->settings : []);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function runtimeUnavailableEntry(Node $node, NodeRoleAssignment $assignment, array $detail, DriftKind $kind): DriftEntry
    {
        return new DriftEntry(
            family: 'tool',
            key: 'tool.websocket.reverb_unavailable',
            kind: $kind,
            summary: "WebSocket Reverb runtime is unavailable on node {$node->name}.",
            detail: [
                'role' => $assignment->role,
                ...$detail,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function redisUnavailableEntry(Node $node, NodeRoleAssignment $assignment, array $detail, DriftKind $kind): DriftEntry
    {
        return new DriftEntry(
            family: 'tool',
            key: 'tool.websocket.redis_unavailable',
            kind: $kind,
            summary: "WebSocket Redis is unavailable to the Reverb runtime on node {$node->name}.",
            detail: [
                'role' => $assignment->role,
                ...$detail,
            ],
        );
    }
}
