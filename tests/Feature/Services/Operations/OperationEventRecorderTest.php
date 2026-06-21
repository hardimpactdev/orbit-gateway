<?php

declare(strict_types=1);

use App\Models\OperationEvent;
use App\Services\Operations\OperationEventRecorder;
use App\Services\Operations\OperationPayloadRejected;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->operationRuns = app(OperationRunRecorder::class);
    $this->recorder = app(OperationEventRecorder::class);
    $this->run = $this->operationRuns->queued((string) Str::uuid(), 'gateway', operationType: 'update:all');
});

it('appends ordered durable operation events', function (): void {
    $tree = $this->recorder->tree($this->run, 'Update all', [
        ['key' => 'gateway', 'label' => 'Update gateway'],
    ]);

    $step = $this->recorder->step($this->run, 'gateway', 'running', 'Updating gateway');
    $complete = $this->recorder->complete($this->run, 0, ['version' => '1.2.3']);

    expect($tree)->toBeInstanceOf(OperationEvent::class)
        ->and($tree->sequence)->toBe(1)
        ->and($step->sequence)->toBe(2)
        ->and($complete->sequence)->toBe(3)
        ->and($complete->payload)->toMatchArray([
            'exit_code' => 0,
            'data' => ['version' => '1.2.3'],
        ])
        ->and($this->run->events()->orderBy('sequence')->pluck('event_type')->all())
        ->toBe(['tree', 'step', 'complete']);
});

it('appends terminal error events with metadata', function (): void {
    $event = $this->recorder->error(
        $this->run,
        message: 'Gateway health failed',
        exitCode: 17,
        data: ['code' => 'gateway_health_failed'],
        metadata: ['phase' => 'gateway'],
    );

    expect($event->event_type)->toBe('error')
        ->and($event->payload)->toMatchArray([
            'exit_code' => 17,
            'message' => 'Gateway health failed',
            'data' => ['code' => 'gateway_health_failed'],
        ])
        ->and($event->metadata)->toMatchArray(['phase' => 'gateway']);
});

it('appends multiple step events in one ordered batch', function (): void {
    $events = $this->recorder->steps($this->run, [
        [
            'key' => 'check-updates',
            'status' => 'done',
            'message' => 'Done: latest version is 1.2.3',
        ],
        [
            'key' => 'check-fleet-versions',
            'status' => 'running',
            'message' => 'Checking',
        ],
    ]);

    expect($events)->toHaveCount(2)
        ->and($events[0]->sequence)->toBe(1)
        ->and($events[1]->sequence)->toBe(2)
        ->and($events[0]->payload)->toMatchArray([
            'key' => 'check-updates',
            'status' => 'done',
            'message' => 'Done: latest version is 1.2.3',
        ])
        ->and($events[1]->payload)->toMatchArray([
            'key' => 'check-fleet-versions',
            'status' => 'running',
            'message' => 'Checking',
        ])
        ->and($this->run->events()->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2]);
});

it('rejects event payloads with forbidden secret keys before writing rows', function (): void {
    expect(fn () => $this->recorder->append($this->run, 'step', [
        'key' => 'gateway',
        'status' => 'running',
        'password' => 'secret',
    ]))->toThrow(OperationPayloadRejected::class, 'operation.progress_unsafe');

    expect(OperationEvent::query()->where('operation_run_id', $this->run->id)->count())->toBe(0);
});

it('rejects event payload values that embed PEM blocks before writing rows', function (): void {
    expect(fn () => $this->recorder->append($this->run, 'step', [
        'key' => 'gateway',
        'status' => 'running',
        'message' => "-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----",
    ]))->toThrow(OperationPayloadRejected::class, 'operation.progress_unsafe');

    expect(OperationEvent::query()->where('operation_run_id', $this->run->id)->count())->toBe(0);
});

it('uses SQLite defaults that support concurrent event reads and writes', function (): void {
    expect(config('database.connections.sqlite.busy_timeout'))->toBe(5000)
        ->and(config('database.connections.sqlite.journal_mode'))->toBe('wal')
        ->and(config('database.connections.sqlite.synchronous'))->toBe('NORMAL');
});
