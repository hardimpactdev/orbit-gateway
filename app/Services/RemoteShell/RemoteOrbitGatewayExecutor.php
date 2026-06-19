<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Runtime\OrbitGatewayContainer;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

final readonly class RemoteOrbitGatewayExecutor implements RemoteExecutor
{
    private const int DEFAULT_TIMEOUT = 120;

    private const string CONTAINER = 'orbit-gateway';

    private const string ARTISAN = 'php apps/gateway/artisan';

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
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    #[\Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $runtimeScript = $this->runtimeScript($node, $script, $options);
        $command = $this->command($node, $runtimeScript);

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
            throw new RemoteShellFailed($node, $runtimeScript, $result);
        }

        return $result;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    #[\Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        $process = $this->pendingProcess($options)->start(
            $this->command($node, $this->runtimeScript($node, $script, $options)),
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
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    private function pendingProcess(array $options): PendingProcess
    {
        $pendingProcess = Process::timeout((int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT));

        if (array_key_exists('input', $options)) {
            return $pendingProcess->input((string) $options['input']);
        }

        return $pendingProcess;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    private function runtimeScript(Node $node, string $script, array $options): string
    {
        $options = $this->optionsWithContainerCwd($node, $options);
        $runtimeCommand = $this->runtimeCommand($script);
        $directCommand = $this->directRuntimeCommand($runtimeCommand['script']);

        if ($directCommand !== null && ! (bool) ($options['strict'] ?? false)) {
            return $this->directDockerExec($directCommand, $options, $runtimeCommand['docker_exec_options']);
        }

        return implode(' ', [
            $this->dockerExecPrefix($options, $runtimeCommand['docker_exec_options']),
            'sh -c',
            escapeshellarg($this->scripts->compose($runtimeCommand['script'], $this->shellFallbackComposeOptions($options))),
        ]);
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     * @param  array{
     *     detach: bool,
     *     detach_keys: string|null,
     *     environment: list<string>,
     *     env_files: list<string>,
     *     privileged: bool,
     *     tty: bool,
     *     user: string|null,
     *     workdir: string|null,
     * }  $dockerExecOptions
     */
    private function directDockerExec(string $runtimeCommand, array $options, array $dockerExecOptions): string
    {
        return $this->dockerExecPrefix($options, $dockerExecOptions).' '.$runtimeCommand;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     * @param  array{
     *     detach: bool,
     *     detach_keys: string|null,
     *     environment: list<string>,
     *     env_files: list<string>,
     *     privileged: bool,
     *     tty: bool,
     *     user: string|null,
     *     workdir: string|null,
     * }  $dockerExecOptions
     *
     * Caller-provided Docker exec flags are emitted before executor metadata and cwd,
     * so direct runtime options override duplicate environment and workdir values.
     * Shell fallback strips those executor options from the composed script body.
     */
    private function dockerExecPrefix(array $options, array $dockerExecOptions, bool $includeExecutorOptions = true): string
    {
        $parts = ['docker exec -i'];

        if ($dockerExecOptions['detach']) {
            $parts[] = '--detach';
        }

        if ($dockerExecOptions['tty']) {
            $parts[] = '--tty';
        }

        if ($dockerExecOptions['privileged']) {
            $parts[] = '--privileged';
        }

        if ($dockerExecOptions['detach_keys'] !== null && $dockerExecOptions['detach_keys'] !== '') {
            $parts[] = '--detach-keys '.escapeshellarg($dockerExecOptions['detach_keys']);
        }

        if ($dockerExecOptions['user'] !== null && $dockerExecOptions['user'] !== '') {
            $parts[] = '--user '.escapeshellarg($dockerExecOptions['user']);
        }

        foreach ($dockerExecOptions['env_files'] as $envFile) {
            $parts[] = '--env-file '.escapeshellarg($envFile);
        }

        foreach ($this->mergedExecEnvironment($options, $dockerExecOptions, $includeExecutorOptions) as $env) {
            $parts[] = '--env '.escapeshellarg($env);
        }

        $workdir = $this->execWorkdir($options, $dockerExecOptions, $includeExecutorOptions);

        if ($workdir !== null && $workdir !== '') {
            $parts[] = '--workdir '.escapeshellarg($workdir);
        }

        $parts[] = self::CONTAINER;

        return implode(' ', $parts);
    }

    private function directRuntimeCommand(string $script): ?string
    {
        $command = $this->normalizeWhitespace($script);

        if ($command === '') {
            return null;
        }

        if ($command === 'artisan') {
            return self::ARTISAN;
        }

        if (str_starts_with($command, 'artisan ')) {
            return $this->safeDirectCommand(self::ARTISAN.' '.substr($command, strlen('artisan ')));
        }

        if ($command === 'php artisan' || str_starts_with($command, 'php artisan ')) {
            return $this->safeDirectCommand(self::ARTISAN.substr($command, strlen('php artisan')));
        }

        return $this->safeDirectCommand($command);
    }

    /**
     * @return array{
     *     script: string,
     *     docker_exec_options: array{
     *         detach: bool,
     *         detach_keys: string|null,
     *         environment: list<string>,
     *         env_files: list<string>,
     *         privileged: bool,
     *         tty: bool,
     *         user: string|null,
     *         workdir: string|null,
     *     },
     * }
     */
    private function runtimeCommand(string $script): array
    {
        return $this->unwrapRuntimeCommand($script) ?? [
            'script' => $script,
            'docker_exec_options' => $this->emptyDockerExecOptions(),
        ];
    }

    /**
     * @return array{
     *     script: string,
     *     docker_exec_options: array{
     *         detach: bool,
     *         detach_keys: string|null,
     *         environment: list<string>,
     *         env_files: list<string>,
     *         privileged: bool,
     *         tty: bool,
     *         user: string|null,
     *         workdir: string|null,
     *     },
     * }|null
     */
    private function unwrapRuntimeCommand(string $command): ?array
    {
        $tokens = $this->shellTokens($command);

        if ($tokens === null || count($tokens) < 4) {
            return null;
        }

        if ($tokens[0]['value'] !== 'docker' || $tokens[1]['value'] !== 'exec') {
            return null;
        }

        $dockerExecOptions = $this->emptyDockerExecOptions();
        $index = 2;

        while (isset($tokens[$index]) && str_starts_with($tokens[$index]['value'], '-')) {
            if (! $this->consumeDockerExecOption($tokens, $index, $dockerExecOptions)) {
                return null;
            }
        }

        if (! isset($tokens[$index]) || $tokens[$index]['value'] !== self::CONTAINER || ! isset($tokens[$index + 1])) {
            return null;
        }

        return [
            'script' => trim(substr($command, $tokens[$index + 1]['start'])),
            'docker_exec_options' => $dockerExecOptions,
        ];
    }

    private function safeDirectCommand(string $command): ?string
    {
        if (preg_match('/\A[A-Za-z0-9_\/.:-]+(?: [A-Za-z0-9_\/.=:,@%+-]+)*\z/', $command) === 1) {
            return $command;
        }

        return null;
    }

    /**
     * @param  list<array{value: string, start: int, end: int}>  $tokens
     * @param  array{
     *     detach: bool,
     *     detach_keys: string|null,
     *     environment: list<string>,
     *     env_files: list<string>,
     *     privileged: bool,
     *     tty: bool,
     *     user: string|null,
     *     workdir: string|null,
     * }  $dockerExecOptions
     */
    private function consumeDockerExecOption(array $tokens, int &$index, array &$dockerExecOptions): bool
    {
        $option = $tokens[$index]['value'];

        if ($this->consumeDockerExecBooleanOption($option, $dockerExecOptions)) {
            $index++;

            return true;
        }

        if ($this->consumeDockerExecValueOption($tokens, $index, $dockerExecOptions, '--detach-keys', 'detach_keys')) {
            return true;
        }

        if ($this->consumeDockerExecValueOption($tokens, $index, $dockerExecOptions, '--env', 'environment')) {
            return true;
        }

        if ($this->consumeDockerExecValueOption($tokens, $index, $dockerExecOptions, '--env-file', 'env_files')) {
            return true;
        }

        if ($this->consumeDockerExecValueOption($tokens, $index, $dockerExecOptions, '--user', 'user')) {
            return true;
        }

        if ($this->consumeDockerExecValueOption($tokens, $index, $dockerExecOptions, '--workdir', 'workdir')) {
            return true;
        }

        if ($this->consumeDockerExecValueOption($tokens, $index, $dockerExecOptions, '-e', 'environment')) {
            return true;
        }

        if ($this->consumeDockerExecValueOption($tokens, $index, $dockerExecOptions, '-u', 'user')) {
            return true;
        }

        return $this->consumeDockerExecValueOption($tokens, $index, $dockerExecOptions, '-w', 'workdir');
    }

    /**
     * @param  array{
     *     detach: bool,
     *     detach_keys: string|null,
     *     environment: list<string>,
     *     env_files: list<string>,
     *     privileged: bool,
     *     tty: bool,
     *     user: string|null,
     *     workdir: string|null,
     * }  $dockerExecOptions
     */
    private function consumeDockerExecBooleanOption(string $option, array &$dockerExecOptions): bool
    {
        if ($option === '-i' || $option === '--interactive') {
            return true;
        }

        if ($option === '-t' || $option === '--tty') {
            $dockerExecOptions['tty'] = true;

            return true;
        }

        if ($option === '-d' || $option === '--detach') {
            $dockerExecOptions['detach'] = true;

            return true;
        }

        if ($option === '--privileged') {
            $dockerExecOptions['privileged'] = true;

            return true;
        }

        if (preg_match('/\A-[dit]+\z/', $option) !== 1) {
            return false;
        }

        foreach (str_split(substr($option, 1)) as $flag) {
            if ($flag === 'd') {
                $dockerExecOptions['detach'] = true;
            }

            if ($flag === 't') {
                $dockerExecOptions['tty'] = true;
            }
        }

        return true;
    }

    /**
     * @param  list<array{value: string, start: int, end: int}>  $tokens
     * @param  array{
     *     detach: bool,
     *     detach_keys: string|null,
     *     environment: list<string>,
     *     env_files: list<string>,
     *     privileged: bool,
     *     tty: bool,
     *     user: string|null,
     *     workdir: string|null,
     * }  $dockerExecOptions
     */
    private function consumeDockerExecValueOption(
        array $tokens,
        int &$index,
        array &$dockerExecOptions,
        string $name,
        string $key,
    ): bool {
        $option = $tokens[$index]['value'];
        $value = null;

        if ($option === $name) {
            if (! isset($tokens[$index + 1])) {
                return false;
            }

            $value = $tokens[$index + 1]['value'];
            $index += 2;
        } elseif (str_starts_with($option, "{$name}=")) {
            $value = substr($option, strlen($name) + 1);
            $index++;
        } elseif (strlen($name) === 2 && str_starts_with($option, $name) && strlen($option) > 2) {
            $value = ltrim(substr($option, 2), '=');
            $index++;
        } else {
            return false;
        }

        $this->recordDockerExecValueOption($dockerExecOptions, $key, $value);

        return true;
    }

    /**
     * @param  array{
     *     detach: bool,
     *     detach_keys: string|null,
     *     environment: list<string>,
     *     env_files: list<string>,
     *     privileged: bool,
     *     tty: bool,
     *     user: string|null,
     *     workdir: string|null,
     * }  $dockerExecOptions
     */
    private function recordDockerExecValueOption(array &$dockerExecOptions, string $key, string $value): void
    {
        if ($key === 'environment') {
            $dockerExecOptions['environment'][] = $value;

            return;
        }

        if ($key === 'env_files') {
            $dockerExecOptions['env_files'][] = $value;

            return;
        }

        if ($key === 'detach_keys') {
            $dockerExecOptions['detach_keys'] = $value;

            return;
        }

        if ($key === 'user') {
            $dockerExecOptions['user'] = $value;

            return;
        }

        if ($key === 'workdir') {
            $dockerExecOptions['workdir'] = $value;
        }
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     * @param  array{
     *     detach: bool,
     *     detach_keys: string|null,
     *     environment: list<string>,
     *     env_files: list<string>,
     *     privileged: bool,
     *     tty: bool,
     *     user: string|null,
     *     workdir: string|null,
     * }  $dockerExecOptions
     * @return list<string>
     */
    private function mergedExecEnvironment(array $options, array $dockerExecOptions, bool $includeExecutorOptions): array
    {
        $environment = [];

        foreach ($dockerExecOptions['environment'] as $env) {
            $environment[$this->environmentKey($env)] = $env;
        }

        if (! $includeExecutorOptions) {
            return array_values($environment);
        }

        foreach ($this->scripts->metadataFromOptions($options, validate: true) as $key => $value) {
            $environment[$key] = "{$key}={$value}";
        }

        return array_values($environment);
    }

    private function environmentKey(string $env): string
    {
        $position = strpos($env, '=');

        if ($position === false) {
            return $env;
        }

        return substr($env, 0, $position);
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     * @param  array{
     *     detach: bool,
     *     detach_keys: string|null,
     *     environment: list<string>,
     *     env_files: list<string>,
     *     privileged: bool,
     *     tty: bool,
     *     user: string|null,
     *     workdir: string|null,
     * }  $dockerExecOptions
     */
    private function execWorkdir(array $options, array $dockerExecOptions, bool $includeExecutorOptions): ?string
    {
        if ($includeExecutorOptions && isset($options['cwd']) && $options['cwd'] !== '') {
            return (string) $options['cwd'];
        }

        return $dockerExecOptions['workdir'];
    }

    /**
     * @return array{
     *     detach: bool,
     *     detach_keys: string|null,
     *     environment: list<string>,
     *     env_files: list<string>,
     *     privileged: bool,
     *     tty: bool,
     *     user: string|null,
     *     workdir: string|null,
     * }
     */
    private function emptyDockerExecOptions(): array
    {
        return [
            'detach' => false,
            'detach_keys' => null,
            'environment' => [],
            'env_files' => [],
            'privileged' => false,
            'tty' => false,
            'user' => null,
            'workdir' => null,
        ];
    }

    private function normalizeWhitespace(string $script): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $script));
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     * @return array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }
     */
    private function shellFallbackComposeOptions(array $options): array
    {
        unset($options['cwd'], $options['metadata']);

        return $options;
    }

    /**
     * @return list<array{value: string, start: int, end: int}>|null
     */
    private function shellTokens(string $command): ?array
    {
        $tokens = [];
        $index = 0;
        $length = strlen($command);

        while ($index < $length) {
            while ($index < $length && ctype_space($command[$index])) {
                $index++;
            }

            if ($index >= $length) {
                break;
            }

            $start = $index;
            $value = '';
            $quote = null;

            while ($index < $length) {
                $character = $command[$index];

                if ($quote === null && ctype_space($character)) {
                    break;
                }

                if (($character === "'" || $character === '"') && ($quote === null || $quote === $character)) {
                    $quote = $quote === $character ? null : $character;
                    $index++;

                    continue;
                }

                if ($character === '\\' && $quote !== "'" && $index + 1 < $length) {
                    $value .= $command[$index + 1];
                    $index += 2;

                    continue;
                }

                $value .= $character;
                $index++;
            }

            if ($quote !== null) {
                return null;
            }

            $tokens[] = [
                'value' => $value,
                'start' => $start,
                'end' => $index,
            ];
        }

        return $tokens;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     * @return array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }
     */
    private function optionsWithContainerCwd(Node $node, array $options): array
    {
        if (! isset($options['cwd']) || $options['cwd'] === '') {
            return $options;
        }

        return [
            ...$options,
            'cwd' => $this->containerCwd($node, (string) $options['cwd']),
        ];
    }

    private function containerCwd(Node $node, string $cwd): string
    {
        $hostOrbitPath = rtrim((string) $node->orbit_path, '/');
        $normalizedCwd = rtrim($cwd, '/');

        if ($hostOrbitPath === '') {
            return $cwd;
        }

        if ($normalizedCwd === $hostOrbitPath) {
            return OrbitGatewayContainer::SourcePath;
        }

        if (str_starts_with($normalizedCwd, "{$hostOrbitPath}/")) {
            return OrbitGatewayContainer::SourcePath.substr($normalizedCwd, strlen($hostOrbitPath));
        }

        return $cwd;
    }

    private function command(Node $node, string $script): string
    {
        if ($this->roleAssignments->nodeIsGateway($node)) {
            return 'bash -c '.escapeshellarg($script);
        }

        return $this->ssh->enforceForNode(
            node: $node,
            remoteCommand: 'bash -lc '.escapeshellarg($script),
            options: [
                'log_level' => 'ERROR',
                'server_alive_interval' => 30,
                'server_alive_count_max' => 10,
            ],
        );
    }
}
