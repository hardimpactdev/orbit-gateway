<?php

declare(strict_types=1);

use App\Models\OperationRun;
use App\Services\Operations\OperationPayloadRejected;
use App\Services\Operations\OperationResultContract;
use App\Services\Operations\OperationResultHandler;
use App\Services\Operations\OperationResultRegistry;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\ResultBoundaryRedactionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var OperationResultRegistry $registry */
    $registry = app(OperationResultRegistry::class);

    $registry->register(new class implements OperationResultContract
    {
        public function operationType(): string
        {
            return 'workspace.setup';
        }

        public function allowedKeys(): array
        {
            return ['workspace_id', 'host_path', 'duration_ms', 'steps'];
        }
    });

    $this->handler = app(OperationResultHandler::class);
    $this->recorder = app(OperationRunRecorder::class);
});

describe('ResultBoundaryRedactionPolicy', function (): void {
    it('rejects payloads with forbidden key fragments', function (string $key): void {
        $policy = new ResultBoundaryRedactionPolicy;

        expect(fn () => $policy->assertSafe([$key => 'value']))
            ->toThrow(OperationPayloadRejected::class);
    })->with([
        'operation_token',
        'executor_secret',
        'password',
        'bearer',
        'secret',
        'access_token',
        'refresh_token',
        'csrf_token',
        'api_key',
        'api_key_id',
    ]);

    it('rejects nested payloads with forbidden key fragments', function (): void {
        $policy = new ResultBoundaryRedactionPolicy;

        expect(fn () => $policy->assertSafe([
            'workspace_id' => 'docs',
            'nested' => [
                'inner' => [
                    'password' => 'leak',
                ],
            ],
        ]))->toThrow(OperationPayloadRejected::class, "'nested.inner.password'");
    });

    it('rejects payloads whose leaf string values contain PEM blocks', function (): void {
        $policy = new ResultBoundaryRedactionPolicy;

        $pem = "-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBAKj34GkxFhD90vcNLYLInFEX6Ppy1tPf9Cnzj4p4WGeKLs1Pt8Q\n-----END RSA PRIVATE KEY-----";

        expect(fn () => $policy->assertSafe(['workspace_id' => $pem]))
            ->toThrow(OperationPayloadRejected::class, 'pem_block_value');
    });

    it('accepts safe payloads with non-secret keys and values', function (): void {
        $policy = new ResultBoundaryRedactionPolicy;

        $policy->assertSafe([
            'workspace_id' => 'docs',
            'host_path' => '/home/orbit/workspaces/docs',
            'duration_ms' => 1234,
            'steps' => [
                ['name' => 'clone', 'exit_code' => 0],
                ['name' => 'install', 'exit_code' => 0],
            ],
        ]);

        expect(true)->toBeTrue();
    });

    it('is case-insensitive for key matching', function (): void {
        $policy = new ResultBoundaryRedactionPolicy;

        expect(fn () => $policy->assertSafe(['Authorization_Bearer' => 'abc']))
            ->toThrow(OperationPayloadRejected::class, 'forbidden_key');
    });

    it('exposes a forbidden-key check helper', function (): void {
        $policy = new ResultBoundaryRedactionPolicy;

        expect($policy->isForbiddenKey('access_token'))->toBeTrue()
            ->and($policy->isForbiddenKey('My_Password'))->toBeTrue()
            ->and($policy->isForbiddenKey('host_path'))->toBeFalse()
            ->and($policy->isForbiddenKey('workspace_id'))->toBeFalse();
    });

    it('exposes a PEM-block check helper', function (): void {
        $policy = new ResultBoundaryRedactionPolicy;
        $pem = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----";

        expect($policy->valueContainsPem($pem))->toBeTrue()
            ->and($policy->valueContainsPem('plain'))->toBeFalse();
    });
});

describe('OperationResultHandler::recordSuccess', function (): void {
    it('persists a recognized typed result and transitions the run to succeeded', function (): void {
        $run = $this->recorder->queued((string) Str::uuid(), 'local', operationType: 'workspace.setup');

        $updated = $this->handler->recordSuccess(
            operationRunId: $run->id,
            operationType: 'workspace.setup',
            result: [
                'workspace_id' => 'docs',
                'host_path' => '/home/orbit/workspaces/docs',
                'duration_ms' => 1234,
            ],
            exitCode: 0,
            stdoutSummary: 'ok',
        );

        expect($updated->status)->toBe(OperationStatus::Succeeded)
            ->and($updated->exit_code)->toBe(0)
            ->and($updated->result)->toMatchArray([
                'workspace_id' => 'docs',
                'host_path' => '/home/orbit/workspaces/docs',
                'duration_ms' => 1234,
            ])
            ->and($updated->stdout_summary)->toBe('ok')
            ->and($updated->finished_at)->not->toBeNull();
    });

    it('rejects results for an unregistered operation_type with operation.result_unrecognized', function (): void {
        $run = $this->recorder->queued((string) Str::uuid(), 'local', operationType: 'tool.install');

        try {
            $this->handler->recordSuccess(
                operationRunId: $run->id,
                operationType: 'tool.install',
                result: ['installed' => true],
            );

            $this->fail('Expected OperationPayloadRejected.');
        } catch (OperationPayloadRejected $exception) {
            expect($exception->errorCode)->toBe('operation.result_unrecognized')
                ->and($exception->meta['operation_type'])->toBe('tool.install');
        }

        expect(OperationRun::query()->find($run->id)->status)->toBe(OperationStatus::Queued);
    });

    it('rejects results that contain unrecognized result keys with operation.result_unrecognized', function (): void {
        $run = $this->recorder->queued((string) Str::uuid(), 'local', operationType: 'workspace.setup');

        try {
            $this->handler->recordSuccess(
                operationRunId: $run->id,
                operationType: 'workspace.setup',
                result: [
                    'workspace_id' => 'docs',
                    'sneaky_extra_field' => 'oops',
                ],
            );

            $this->fail('Expected OperationPayloadRejected.');
        } catch (OperationPayloadRejected $exception) {
            expect($exception->errorCode)->toBe('operation.result_unrecognized')
                ->and($exception->meta['unrecognized_key'])->toBe('sneaky_extra_field')
                ->and($exception->meta['allowed_keys'])->toContain('workspace_id');
        }

        expect(OperationRun::query()->find($run->id)->status)->toBe(OperationStatus::Queued);
    });

    it('rejects fabricated results carrying any forbidden secret key before writing to operation_runs', function (string $secretKey): void {
        $run = $this->recorder->queued((string) Str::uuid(), 'local', operationType: 'workspace.setup');

        try {
            $this->handler->recordSuccess(
                operationRunId: $run->id,
                operationType: 'workspace.setup',
                result: [
                    'workspace_id' => 'docs',
                    'steps' => [
                        ['name' => 'clone', $secretKey => 'leaked'],
                    ],
                ],
            );

            $this->fail("Expected OperationPayloadRejected for forbidden key '{$secretKey}'.");
        } catch (OperationPayloadRejected $exception) {
            expect($exception->errorCode)->toBe('operation.result_unsafe')
                ->and($exception->meta['reason'])->toBe('forbidden_key');
        }

        expect(OperationRun::query()->find($run->id)->status)->toBe(OperationStatus::Queued)
            ->and(OperationRun::query()->find($run->id)->result)->toBeNull();
    })->with([
        'operation_token',
        'executor_secret',
        'password',
        'bearer',
        'secret',
        'access_token',
        'api_key',
    ]);

    it('rejects results whose values embed PEM blocks before writing to operation_runs', function (): void {
        $run = $this->recorder->queued((string) Str::uuid(), 'local', operationType: 'workspace.setup');
        $pem = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAABG5vbmU=\n-----END OPENSSH PRIVATE KEY-----";

        try {
            $this->handler->recordSuccess(
                operationRunId: $run->id,
                operationType: 'workspace.setup',
                result: [
                    'workspace_id' => 'docs',
                    'host_path' => $pem,
                ],
            );

            $this->fail('Expected OperationPayloadRejected for PEM block.');
        } catch (OperationPayloadRejected $exception) {
            expect($exception->errorCode)->toBe('operation.result_unsafe')
                ->and($exception->meta['reason'])->toBe('pem_block_value');
        }

        expect(OperationRun::query()->find($run->id)->result)->toBeNull();
    });
});

describe('OperationResultHandler::recordFailure', function (): void {
    it('persists a typed failure payload after the redaction policy passes', function (): void {
        $run = $this->recorder->queued((string) Str::uuid(), 'local', operationType: 'workspace.setup');

        $failed = $this->handler->recordFailure(
            operationRunId: $run->id,
            error: ['code' => 'remote_shell_failed', 'message' => 'host unreachable'],
            exitCode: 17,
            stderrSummary: 'connection refused',
        );

        expect($failed->status)->toBe(OperationStatus::Failed)
            ->and($failed->exit_code)->toBe(17)
            ->and($failed->error)->toMatchArray(['code' => 'remote_shell_failed'])
            ->and($failed->stderr_summary)->toBe('connection refused');
    });

    it('rejects failure payloads that contain forbidden secret keys before writing', function (): void {
        $run = $this->recorder->queued((string) Str::uuid(), 'local', operationType: 'workspace.setup');

        try {
            $this->handler->recordFailure(
                operationRunId: $run->id,
                error: [
                    'code' => 'remote_shell_failed',
                    'leaked_password' => 'oops',
                ],
            );

            $this->fail('Expected OperationPayloadRejected for forbidden key in failure payload.');
        } catch (OperationPayloadRejected $exception) {
            expect($exception->errorCode)->toBe('operation.error_unsafe');
        }

        expect(OperationRun::query()->find($run->id)->status)->toBe(OperationStatus::Queued)
            ->and(OperationRun::query()->find($run->id)->error)->toBeNull();
    });
});
