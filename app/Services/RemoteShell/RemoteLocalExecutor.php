<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Contracts\Loggable;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ActivityLogType;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class RemoteLocalExecutor implements RemoteExecutor
{
    private const string LOCAL_EXECUTOR_HOME = '/home/orbit';

    private const string OPERATION_ID_METADATA_KEY = 'ORBIT_OPERATION_ID';

    private const int OUTPUT_SUMMARY_BYTES = 4_096;

    private const string TRUNCATED_SUFFIX = '[truncated]';

    private const string SUPPRESSED_OUTPUT_SUMMARY = '<suppressed>';

    private const string REDACTED_VALUE = '<redacted>';

    private const string COMMAND_OPTION_KEY_PATTERN = '/\A[a-z][a-z0-9-]*\z/';

    private const string START_UNSUPPORTED_MESSAGE = 'RemoteLocalExecutor::startInternal() is not supported. Long-running local-executor processes are not currently audited; use runInternal() for completion-based dispatch. See apps/docs/content/execution-lanes.md.';

    public function __construct(
        private RemoteExecutor $transport,
        private LocalExecutorCommandBuilder $commands,
        private OperationTokenFactory $operationTokens,
        private ActivityLogger $activityLogger,
        private OperationRunRecorder $operationRuns,
        private string $operationTokenSecret,
    ) {
        if (trim($this->operationTokenSecret) === '') {
            throw new RuntimeException('Operation token signing secret is required.');
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
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $options
     */
    #[\Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return $this->runInternal(
            node: $node,
            commandName: $script,
            arguments: [],
            commandOptions: [],
            transportOptions: $options,
        );
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $operationId = $this->operationId($transportOptions);
        $dispatch = $this->dispatchCommand(
            node: $node,
            commandName: $commandName,
            arguments: $arguments,
            commandOptions: $commandOptions,
            operationId: $operationId,
        );

        $run = $this->operationRuns->queued(
            operationId: $operationId,
            lane: 'local',
            internalCommand: $commandName,
            targetNodeId: $node->getKey(),
        );
        $this->operationRuns->running($run->id);

        try {
            $this->logDispatching(
                node: $node,
                commandName: $commandName,
                arguments: $arguments,
                commandOptions: $commandOptions,
                operationId: $operationId,
                auditLine: $dispatch['auditLine'],
                transportOptions: $transportOptions,
            );

            $result = $this->transport->run(
                node: $node,
                script: $dispatch['script'],
                options: $this->transportDispatchOptions($transportOptions),
            );
        } catch (RemoteShellFailed $exception) {
            $sanitizedResult = $this->sanitizedResult($exception->result, $dispatch['operationToken']);

            $this->operationRuns->failed(
                id: $run->id,
                exitCode: $sanitizedResult->exitCode,
                error: [
                    'code' => 'remote_shell_failed',
                    'duration_ms' => $sanitizedResult->durationMs,
                ],
                stdoutSummary: $this->outputSummary($sanitizedResult->stdout, $dispatch['operationToken'], (bool) ($transportOptions['redact_stdout'] ?? false)),
                stderrSummary: $this->outputSummary($sanitizedResult->stderr, $dispatch['operationToken'], (bool) ($transportOptions['redact_stderr'] ?? false)),
            );

            $this->logCompleted(
                node: $node,
                commandName: $commandName,
                operationId: $operationId,
                result: $sanitizedResult,
                operationToken: $dispatch['operationToken'],
                transportOptions: $transportOptions,
            );

            throw new RemoteShellFailed(
                node: $exception->node,
                script: $this->redactOperationToken($exception->script, $dispatch['operationToken']),
                result: $sanitizedResult,
            );
        } catch (Throwable $throwable) {
            $redactedMessage = $this->transportExceptionMessageSummary(
                throwable: $throwable,
                operationToken: $dispatch['operationToken'],
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            );
            $redactedMetadata = $this->transportExceptionMetadata(
                throwable: $throwable,
                operationToken: $dispatch['operationToken'],
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            );

            $this->operationRuns->failed(
                id: $run->id,
                error: [
                    'code' => 'transport_failed',
                    'class' => $throwable::class,
                    'message' => $redactedMessage,
                ],
            );

            $this->logTransportException(
                node: $node,
                commandName: $commandName,
                operationId: $operationId,
                throwable: $throwable,
                exceptionMessage: $redactedMessage,
            );

            throw new RemoteLocalExecutorTransportFailed(
                message: "Remote local executor transport failed: {$redactedMessage}",
                meta: $redactedMetadata,
                code: (int) $throwable->getCode(),
            );
        }

        $this->operationRuns->succeeded(
            id: $run->id,
            exitCode: $result->exitCode,
            stdoutSummary: $this->outputSummary($result->stdout, $dispatch['operationToken'], (bool) ($transportOptions['redact_stdout'] ?? false)),
            stderrSummary: $this->outputSummary($result->stderr, $dispatch['operationToken'], (bool) ($transportOptions['redact_stderr'] ?? false)),
        );

        $this->logCompleted(
            node: $node,
            commandName: $commandName,
            operationId: $operationId,
            result: $result,
            operationToken: $dispatch['operationToken'],
            transportOptions: $transportOptions,
        );

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
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $options
     */
    #[\Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException(self::START_UNSUPPORTED_MESSAGE);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     */
    public function startInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): InvokedProcess {
        throw new RuntimeException(self::START_UNSUPPORTED_MESSAGE);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @return array{operationToken: string, script: string, auditLine: string}
     */
    private function dispatchCommand(
        Node $node,
        string $commandName,
        array $arguments,
        array $commandOptions,
        string $operationId,
    ): array {
        $this->commands->build(
            targetNode: $node,
            commandName: $commandName,
            arguments: $arguments,
            options: $commandOptions,
            operationToken: 'validation-placeholder',
        );

        $operationToken = $this->operationTokens->mint(
            operationId: $operationId,
            targetNode: (string) $node->name,
            command: $commandName,
        )->toString();

        return [
            'operationToken' => $operationToken,
            'script' => $this->commands->build(
                targetNode: $node,
                commandName: $commandName,
                arguments: $arguments,
                options: $commandOptions,
                operationToken: $operationToken,
            ),
            'auditLine' => $this->commands->buildAuditLine(
                targetNode: $node,
                commandName: $commandName,
                arguments: $arguments,
                options: $commandOptions,
                operationToken: $operationToken,
            ),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     */
    private function logDispatching(
        Node $node,
        string $commandName,
        array $arguments,
        array $commandOptions,
        string $operationId,
        string $auditLine,
        array $transportOptions,
    ): void {
        $redactedCommandOptionNames = $this->redactedCommandOptionNames($transportOptions);

        $this->activityLogger->log(
            new LocalExecutorActivity(
                event: 'local_executor.dispatching',
                subject: $node,
                description: 'Local executor operation dispatching',
                properties: [
                    'lane' => 'local-executor',
                    'status' => 'dispatching',
                    'operation_id' => $operationId,
                    'target_node_id' => $node->getKey(),
                    'target_node_name' => (string) $node->name,
                    'command' => $commandName,
                    'arguments' => $this->scalarPayload($arguments),
                    'command_options' => $this->scalarPayload($commandOptions, $redactedCommandOptionNames),
                    'command_line' => $this->redactCommandOptionsInLine($auditLine, $redactedCommandOptionNames),
                ],
            ),
            channel: 'local_executor',
            causer: null,
        );
    }

    private function logCompleted(
        Node $node,
        string $commandName,
        string $operationId,
        RemoteShellResult $result,
        string $operationToken,
        array $transportOptions,
    ): void {
        $status = $result->successful() ? 'succeeded' : 'failed';

        $this->activityLogger->log(
            new LocalExecutorActivity(
                event: 'local_executor.completed',
                subject: $node,
                description: "Local executor operation {$status}",
                properties: [
                    'lane' => 'local-executor',
                    'status' => $status,
                    'operation_id' => $operationId,
                    'target_node_id' => $node->getKey(),
                    'target_node_name' => (string) $node->name,
                    'command' => $commandName,
                    'exit_code' => $result->exitCode,
                    'stdout_summary' => $this->outputSummary($result->stdout, $operationToken, (bool) ($transportOptions['redact_stdout'] ?? false)),
                    'stderr_summary' => $this->outputSummary($result->stderr, $operationToken, (bool) ($transportOptions['redact_stderr'] ?? false)),
                    'duration_ms' => $result->durationMs,
                ],
            ),
            channel: 'local_executor',
            causer: null,
        );
    }

    private function logTransportException(
        Node $node,
        string $commandName,
        string $operationId,
        Throwable $throwable,
        string $exceptionMessage,
    ): void {
        $this->activityLogger->log(
            new LocalExecutorActivity(
                event: 'local_executor.completed',
                subject: $node,
                description: 'Local executor operation failed',
                properties: [
                    'lane' => 'local-executor',
                    'status' => 'failed',
                    'operation_id' => $operationId,
                    'target_node_id' => $node->getKey(),
                    'target_node_name' => (string) $node->name,
                    'command' => $commandName,
                    'exit_code' => null,
                    'stdout_summary' => '',
                    'stderr_summary' => '',
                    'exception_class' => $throwable::class,
                    'exception_message' => $exceptionMessage,
                ],
            ),
            channel: 'local_executor',
            causer: null,
        );
    }

    private function sanitizedResult(RemoteShellResult $result, string $operationToken): RemoteShellResult
    {
        return new RemoteShellResult(
            exitCode: $result->exitCode,
            stdout: $this->redactOperationToken($result->stdout, $operationToken),
            stderr: $this->redactOperationToken($result->stderr, $operationToken),
            durationMs: $result->durationMs,
        );
    }

    private function outputSummary(string $output, string $operationToken, bool $suppress = false): string
    {
        if ($suppress) {
            return self::SUPPRESSED_OUTPUT_SUMMARY;
        }

        return $this->truncate($this->redactOperationToken($output, $operationToken));
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     */
    private function transportExceptionMessageSummary(
        Throwable $throwable,
        string $operationToken,
        array $transportOptions,
        array $commandOptions,
    ): string {
        return $this->outputSummary(
            output: $this->redactExceptionText(
                value: $throwable->getMessage(),
                operationToken: $operationToken,
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            ),
            operationToken: $operationToken,
            suppress: $this->shouldSuppressExceptionMessage($transportOptions),
        );
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     * @return array<array-key, mixed>
     */
    private function transportExceptionMetadata(
        Throwable $throwable,
        string $operationToken,
        array $transportOptions,
        array $commandOptions,
    ): array {
        $metadata = $this->rawTransportExceptionMetadata($throwable);

        if ($metadata === []) {
            return [];
        }

        return $this->redactExceptionMetadata(
            metadata: $metadata,
            operationToken: $operationToken,
            transportOptions: $transportOptions,
            commandOptions: $commandOptions,
        );
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     */
    private function shouldSuppressExceptionMessage(array $transportOptions): bool
    {
        return (bool) ($transportOptions['redact_stdout'] ?? false)
            || (bool) ($transportOptions['redact_stderr'] ?? false);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function rawTransportExceptionMetadata(Throwable $throwable): array
    {
        $reflection = new \ReflectionObject($throwable);

        if (! $reflection->hasProperty('meta')) {
            return [];
        }

        $property = $reflection->getProperty('meta');

        if (! $property->isPublic()) {
            return [];
        }

        $metadata = $property->getValue($throwable);

        return is_array($metadata) ? $metadata : [];
    }

    private function redactOperationToken(string $value, string $operationToken): string
    {
        $redacted = preg_replace(
            '/--operation-token\s*(?:=\s*|\s+)(?:"[^"]*"|\'[^\']*\'|\S+)/',
            '--operation-token='.self::REDACTED_VALUE,
            $value,
        ) ?? $value;

        if ($operationToken === '') {
            return $redacted;
        }

        return str_replace($operationToken, self::REDACTED_VALUE, $redacted);
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     */
    private function redactExceptionText(
        string $value,
        string $operationToken,
        array $transportOptions,
        array $commandOptions,
    ): string {
        return $this->redactOperationToken(
            $this->redactCommandOptionSecrets($value, $transportOptions, $commandOptions),
            $operationToken,
        );
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     */
    private function redactCommandOptionSecrets(string $value, array $transportOptions, array $commandOptions): string
    {
        $optionNames = $this->redactedCommandOptionNames($transportOptions);

        if ($optionNames === []) {
            return $value;
        }

        $redacted = $this->redactCommandOptionsInLine($value, $optionNames);

        foreach ($this->redactedCommandOptionValues($commandOptions, $optionNames) as $optionValue) {
            $redacted = str_replace($optionValue, self::REDACTED_VALUE, $redacted);
        }

        return $redacted;
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     * @return array<array-key, mixed>
     */
    private function redactExceptionMetadata(
        array $metadata,
        string $operationToken,
        array $transportOptions,
        array $commandOptions,
    ): array {
        $optionNames = $this->redactedCommandOptionNames($transportOptions);
        $redacted = [];

        foreach ($metadata as $key => $value) {
            if (in_array($key, $optionNames, true)) {
                $redacted[$key] = self::REDACTED_VALUE;

                continue;
            }

            $redacted[$key] = $this->redactExceptionMetadataValue(
                value: $value,
                operationToken: $operationToken,
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            );
        }

        return $redacted;
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     */
    private function redactExceptionMetadataValue(
        mixed $value,
        string $operationToken,
        array $transportOptions,
        array $commandOptions,
    ): mixed {
        if (is_string($value)) {
            return $this->redactExceptionText($value, $operationToken, $transportOptions, $commandOptions);
        }

        if (is_array($value)) {
            return $this->redactExceptionMetadata($value, $operationToken, $transportOptions, $commandOptions);
        }

        if (is_bool($value) || is_float($value) || is_int($value) || $value === null) {
            return $value;
        }

        return self::REDACTED_VALUE;
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  list<string>  $optionNames
     * @return list<string>
     */
    private function redactedCommandOptionValues(array $commandOptions, array $optionNames): array
    {
        $values = [];

        foreach ($optionNames as $optionName) {
            $optionValue = $commandOptions[$optionName] ?? null;

            if (! is_string($optionValue) || $optionValue === '') {
                continue;
            }

            if (! in_array($optionValue, $values, true)) {
                $values[] = $optionValue;
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $optionNames
     */
    private function redactCommandOptionsInLine(string $value, array $optionNames): string
    {
        $redacted = $value;

        foreach ($optionNames as $optionName) {
            $redacted = preg_replace(
                '/--'.preg_quote($optionName, '/').'\s*(?:=\s*|\s+)(?:"[^"]*"|\'[^\']*\'|\S+)/',
                "--{$optionName}=".self::REDACTED_VALUE,
                $redacted,
            ) ?? $redacted;
        }

        return $redacted;
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= self::OUTPUT_SUMMARY_BYTES) {
            return $value;
        }

        return substr($value, 0, self::OUTPUT_SUMMARY_BYTES).self::TRUNCATED_SUFFIX;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  list<string>  $redactedKeys
     * @return array<int|string, bool|float|int|string>
     */
    private function scalarPayload(array $values, array $redactedKeys = []): array
    {
        $payload = [];

        foreach ($values as $key => $value) {
            if (is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
                $payload[$key] = is_string($key) && in_array($key, $redactedKeys, true)
                    ? self::REDACTED_VALUE
                    : $value;
            }
        }

        return $payload;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     * @return list<string>
     */
    private function redactedCommandOptionNames(array $transportOptions): array
    {
        $optionNames = $transportOptions['redact_command_options'] ?? [];

        if ($optionNames === []) {
            return [];
        }

        if (! is_array($optionNames) || ! array_is_list($optionNames)) {
            throw new RuntimeException('redact_command_options must be a list of command option names.');
        }

        $redacted = [];

        foreach ($optionNames as $optionName) {
            if (! is_string($optionName) || preg_match(self::COMMAND_OPTION_KEY_PATTERN, $optionName) !== 1) {
                throw new RuntimeException('redact_command_options must be a list of command option names.');
            }

            if (! in_array($optionName, $redacted, true)) {
                $redacted[] = $optionName;
            }
        }

        return $redacted;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     */
    private function operationId(array $transportOptions): string
    {
        $metadata = $transportOptions['metadata'] ?? [];
        $operationId = $metadata[self::OPERATION_ID_METADATA_KEY] ?? null;

        if (is_string($operationId) && trim($operationId) !== '') {
            return $operationId;
        }

        return (string) Str::uuid();
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     * @return array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }
     */
    private function transportDispatchOptions(array $transportOptions): array
    {
        unset($transportOptions['redact_stdout'], $transportOptions['redact_stderr'], $transportOptions['redact_command_options']);

        $environment = $this->transportEnvironment($transportOptions);
        $environment['HOME'] = self::LOCAL_EXECUTOR_HOME;
        $environment['ORBIT_CONFIG_PATH'] = self::LOCAL_EXECUTOR_HOME.'/.config/orbit/config.json';
        $environment['APP_KEY'] = $this->operationTokenSecret;
        $transportOptions['environment'] = $environment;

        return $transportOptions;
    }

    /**
     * @param  array<string, mixed>  $transportOptions
     * @return array<string, string>
     */
    private function transportEnvironment(array $transportOptions): array
    {
        $environment = $transportOptions['environment'] ?? [];

        if ($environment === []) {
            return [];
        }

        if (! is_array($environment)) {
            throw new RuntimeException('environment must be an array of string values.');
        }

        $resolved = [];

        foreach ($environment as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new RuntimeException('environment must be an array of string values.');
            }

            $resolved[$key] = $value;
        }

        return $resolved;
    }
}

final readonly class LocalExecutorActivity implements Loggable
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        private string $event,
        private Node $subject,
        private string $description,
        private array $properties,
    ) {}

    #[\Override]
    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    #[\Override]
    public function type(): string
    {
        return $this->event;
    }

    #[\Override]
    public function subject(): Model
    {
        return $this->subject;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function properties(): array
    {
        return $this->properties;
    }

    #[\Override]
    public function description(): string
    {
        return $this->description;
    }
}

final class RemoteLocalExecutorTransportFailed extends RuntimeException
{
    /**
     * @param  array<array-key, mixed>  $meta
     */
    public function __construct(string $message, public readonly array $meta = [], int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
