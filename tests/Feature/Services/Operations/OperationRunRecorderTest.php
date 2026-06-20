<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\OperationRun;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->recorder = app(OperationRunRecorder::class);
});

describe('OperationRunRecorder', function (): void {
    it('records a queued operation_run with the recorder-minted per-attempt id', function (): void {
        $operationId = (string) Str::uuid();

        $run = $this->recorder->queued(
            operationId: $operationId,
            lane: 'host',
            internalCommand: 'internal:workspace-adapter:lookup',
            callerNodeId: null,
            targetNodeId: null,
        );

        expect($run)->toBeInstanceOf(OperationRun::class)
            ->and($run->status)->toBe(OperationStatus::Queued)
            ->and($run->id)->not->toBe($operationId)
            ->and($run->operation_id)->toBe($operationId)
            ->and($run->lane)->toBe('host')
            ->and($run->internal_command)->toBe('internal:workspace-adapter:lookup')
            ->and($run->started_at)->toBeNull()
            ->and($run->finished_at)->toBeNull();
    });

    it('persists optional node references when given', function (): void {
        $caller = Node::factory()->create();
        $target = Node::factory()->create();

        $run = $this->recorder->queued(
            operationId: (string) Str::uuid(),
            lane: 'runtime',
            operationType: 'workspace.setup',
            callerNodeId: $caller->id,
            targetNodeId: $target->id,
            correlationId: (string) Str::uuid(),
            queue: 'orbit-default',
        );

        expect($run->caller_node_id)->toBe($caller->id)
            ->and($run->target_node_id)->toBe($target->id)
            ->and($run->operation_type)->toBe('workspace.setup')
            ->and($run->queue)->toBe('orbit-default')
            ->and($run->correlation_id)->not->toBeNull();
    });

    it('rejects invalid lane values at the recorder before touching the table', function (): void {
        expect(fn () => $this->recorder->queued(
            operationId: (string) Str::uuid(),
            lane: 'bogus',
        ))->toThrow(RuntimeException::class, 'host, runtime, local, gateway');
    });

    it('also enforces the lane invariant at the database via a SQL trigger', function (): void {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Lane CHECK trigger is SQLite-specific.');
        }

        $row = [
            'id' => (string) Str::uuid(),
            'operation_id' => (string) Str::uuid(),
            'lane' => 'host',
            'status' => OperationStatus::Queued->value,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('operation_runs')->insert($row);

        expect(fn () => DB::table('operation_runs')
            ->where('id', $row['id'])
            ->update(['lane' => 'bogus']))
            ->toThrow(Exception::class, 'host, runtime, local, gateway');

        $insertable = $row;
        $insertable['id'] = (string) Str::uuid();
        $insertable['lane'] = 'bogus';

        expect(fn () => DB::table('operation_runs')->insert($insertable))
            ->toThrow(Exception::class, 'host, runtime, local, gateway');
    });

    it('marks a queued run as running and sets started_at exactly once', function (): void {
        $run = $this->recorder->queued((string) Str::uuid(), 'host');

        $started = $this->recorder->running($run->id);
        $firstStartedAt = $started->started_at;

        $startedAgain = $this->recorder->running($run->id);

        expect($started->status)->toBe(OperationStatus::Running)
            ->and($firstStartedAt)->not->toBeNull()
            ->and($startedAgain->started_at?->toIso8601String())->toBe($firstStartedAt?->toIso8601String());
    });

    it('finalizes a run as succeeded with exit code and result', function (): void {
        $run = $this->recorder->queued((string) Str::uuid(), 'gateway', operationType: 'tool.install');
        $this->recorder->running($run->id);

        $done = $this->recorder->succeeded(
            id: $run->id,
            exitCode: 0,
            result: ['installed' => true],
            stdoutSummary: 'ok',
            stderrSummary: '',
        );

        expect($done->status)->toBe(OperationStatus::Succeeded)
            ->and($done->exit_code)->toBe(0)
            ->and($done->result)->toMatchArray(['installed' => true])
            ->and($done->stdout_summary)->toBe('ok')
            ->and($done->finished_at)->not->toBeNull();
    });

    it('finalizes a run as failed with error payload', function (): void {
        $run = $this->recorder->queued((string) Str::uuid(), 'host', internalCommand: 'internal:executor:verify');

        $failed = $this->recorder->failed(
            id: $run->id,
            exitCode: 17,
            error: ['code' => 'remote_shell_failed', 'message' => 'host unreachable'],
            stderrSummary: 'connection refused',
        );

        expect($failed->status)->toBe(OperationStatus::Failed)
            ->and($failed->exit_code)->toBe(17)
            ->and($failed->error)->toMatchArray(['code' => 'remote_shell_failed'])
            ->and($failed->stderr_summary)->toBe('connection refused')
            ->and($failed->result)->toBeNull();
    });

    it('finalizes rejected and expired runs without exit codes or output', function (): void {
        $rejectedRun = $this->recorder->queued((string) Str::uuid(), 'host');
        $expiredRun = $this->recorder->queued((string) Str::uuid(), 'host');

        $rejected = $this->recorder->rejected($rejectedRun->id, ['reason' => 'token_signature_invalid']);
        $expired = $this->recorder->expired($expiredRun->id);

        expect($rejected->status)->toBe(OperationStatus::Rejected)
            ->and($rejected->error)->toMatchArray(['reason' => 'token_signature_invalid'])
            ->and($rejected->exit_code)->toBeNull()
            ->and($expired->status)->toBe(OperationStatus::Expired)
            ->and($expired->exit_code)->toBeNull()
            ->and($expired->finished_at)->not->toBeNull();
    });

    it('throws when finalizing an operation_run that does not exist', function (): void {
        expect(fn () => $this->recorder->running('00000000-0000-0000-0000-000000000000'))
            ->toThrow(RuntimeException::class, 'OperationRun');
    });

    it('supports re-minting the same operation_id with fresh per-attempt ids', function (): void {
        $operationId = (string) Str::uuid();

        $first = $this->recorder->queued($operationId, 'host');
        $this->recorder->failed($first->id, exitCode: 1, error: ['code' => 'remote_shell_failed']);

        $second = $this->recorder->queued($operationId, 'host');
        $this->recorder->succeeded($second->id, exitCode: 0, result: ['ok' => true]);

        $rows = OperationRun::query()->where('operation_id', $operationId)->orderBy('created_at')->get();

        expect($rows)->toHaveCount(2)
            ->and($rows[0]->id)->not->toBe($rows[1]->id)
            ->and($rows[0]->operation_id)->toBe($operationId)
            ->and($rows[1]->operation_id)->toBe($operationId)
            ->and($rows[0]->status)->toBe(OperationStatus::Failed)
            ->and($rows[1]->status)->toBe(OperationStatus::Succeeded);
    });
});

describe('activity_log linkage', function (): void {
    it('exposes a nullable operation_run_id foreign key on the Spatie activity_log table', function (): void {
        $tableName = config('activitylog.table_name');

        expect(Schema::hasColumn($tableName, 'operation_run_id'))->toBeTrue();
    });

    it('accepts the operation_runs.id uuid in activity_log.operation_run_id', function (): void {
        $run = $this->recorder->queued((string) Str::uuid(), 'host', internalCommand: 'internal:executor:verify');

        $tableName = config('activitylog.table_name');

        DB::connection(config('activitylog.database_connection'))
            ->table($tableName)
            ->insert([
                'log_name' => 'local_executor.dispatching',
                'description' => 'Dispatched internal:executor:verify',
                'properties' => '{}',
                'operation_run_id' => $run->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $row = DB::connection(config('activitylog.database_connection'))
            ->table($tableName)
            ->where('operation_run_id', $run->id)
            ->first();

        expect($row)->not->toBeNull()
            ->and($row->log_name)->toBe('local_executor.dispatching');
    });
});

describe('orbit.operation_runs config', function (): void {
    it('reads the retention window from orbit config with a 90-day default', function (): void {
        expect(config('orbit.operation_runs.retention_days', 90))->toBe(90);
    });
});
