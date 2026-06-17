<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

final readonly class RemoteHostExecutor implements RemoteExecutor
{
    private const int DEFAULT_TIMEOUT = 120;

    public function __construct(
        private RemoteShellScriptComposer $scripts,
        private SshCommandBuilder $ssh,
        private NodeRoleAssignments $roleAssignments,
        private RemoteShellAuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    #[\Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $composedScript = $this->scripts->compose($script, $options);
        $command = $this->command($node, $composedScript);

        $startedAt = hrtime(true);
        $processResult = $this->pendingProcess($options)->run($command);
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $result = new RemoteShellResult(
            exitCode: $processResult->exitCode() ?? 1,
            stdout: $processResult->output(),
            stderr: $processResult->errorOutput(),
            durationMs: $durationMs,
        );

        $this->auditLogger->log('remote_shell.run', $node, $script, $options, $result);

        if ((bool) ($options['throw'] ?? false) && ! $result->successful()) {
            throw new RemoteShellFailed($node, $composedScript, $result);
        }

        return $result;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    #[\Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        $process = $this->pendingProcess($options)->start(
            $this->command($node, $this->scripts->compose($script, $options)),
        );

        $this->auditLogger->log('remote_shell.start', $node, $script, $options);

        return $process;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    private function pendingProcess(array $options): PendingProcess
    {
        $pendingProcess = Process::timeout((int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT));

        $environment = $this->environment($options);

        if ($environment !== []) {
            $pendingProcess = $pendingProcess->env($environment);
        }

        if (array_key_exists('input', $options)) {
            return $pendingProcess->input((string) $options['input']);
        }

        return $pendingProcess;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function environment(array $options): array
    {
        $environment = $options['environment'] ?? [];

        if ($environment === []) {
            return [];
        }

        if (! is_array($environment)) {
            return [];
        }

        $resolved = [];

        foreach ($environment as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            $resolved[$key] = $value;
        }

        return $resolved;
    }

    private function command(Node $node, string $script): string
    {
        if ($this->roleAssignments->nodeIsGateway($node) && ! $this->runningInsideOrbitGateway()) {
            return 'bash -c '.escapeshellarg($script);
        }

        return $this->ssh->enforceForNode(
            node: $node,
            remoteCommand: 'bash -lc '.escapeshellarg($this->scriptWithE2eDockerEnvironment($node, $script)),
            options: [
                'log_level' => 'ERROR',
                'server_alive_interval' => 30,
                'server_alive_count_max' => 10,
            ],
        );
    }

    private function scriptWithE2eDockerEnvironment(Node $node, string $script): string
    {
        $environment = $this->e2eDockerEnvironment($node);

        if ($environment === []) {
            return $script;
        }

        $exports = array_map(
            fn (string $key, string $value): string => escapeshellarg("{$key}={$value}"),
            array_keys($environment),
            array_values($environment),
        );

        return implode(' ', ['env', ...$exports, 'bash', '-lc', escapeshellarg($script)]);
    }

    /**
     * @return array<string, string>
     */
    private function e2eDockerEnvironment(Node $node): array
    {
        $network = $this->e2eEnvironmentValue('ORBIT_E2E_DOCKER_NETWORK');

        if ($network === null) {
            return [];
        }

        return [
            'ORBIT_E2E_DOCKER_NETWORK' => $network,
            'ORBIT_NODE_CONTAINER' => $this->e2eNodeContainer($node, $network),
        ];
    }

    private function e2eNodeContainer(Node $node, string $network): string
    {
        $scope = $this->nodeContainerScope($node);

        if (str_starts_with($scope, "{$network}-")) {
            return $scope;
        }

        return $this->dockerName($network, $scope);
    }

    private function nodeContainerScope(Node $node): string
    {
        $host = is_string($node->host) ? trim($node->host) : '';

        if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return $this->sanitizeDockerName($host);
        }

        return $this->sanitizeDockerName($node->name);
    }

    private function dockerName(string ...$parts): string
    {
        return implode('-', array_map($this->sanitizeDockerName(...), $parts));
    }

    private function sanitizeDockerName(string $value): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', trim($value)) ?? '';

        return trim($sanitized, '-');
    }

    private function e2eEnvironmentValue(string $key): ?string
    {
        $processValue = getenv($key);

        if (is_string($processValue) && trim($processValue) !== '') {
            return trim($processValue);
        }

        $serverValue = $_SERVER[$key] ?? null;

        if (is_string($serverValue) && trim($serverValue) !== '') {
            return trim($serverValue);
        }

        $envValue = $_ENV[$key] ?? null;

        return is_string($envValue) && trim($envValue) !== '' ? trim($envValue) : null;
    }

    private function runningInsideOrbitGateway(): bool
    {
        $exposureMode = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');

        if (is_string($exposureMode) && trim($exposureMode) !== '') {
            return true;
        }

        $hostPath = getenv('ORBIT_HOST_PATH');

        if (is_string($hostPath) && trim($hostPath) !== '') {
            return true;
        }

        $sourcePath = getenv('ORBIT_SOURCE_PATH');

        return is_string($sourcePath) && trim($sourcePath) === '/opt/orbit';
    }
}
