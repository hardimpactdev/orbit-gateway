<?php

declare(strict_types=1);

use App\Models\OperationEvent;
use App\Services\Operations\OperationEventRecorder;
use App\Services\Operations\OperationEventStreamer;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->operationRuns = app(OperationRunRecorder::class);
    $this->events = app(OperationEventRecorder::class);
    $this->streamer = app(OperationEventStreamer::class);
    $this->run = $this->operationRuns->queued((string) Str::uuid(), 'gateway', operationType: 'update:all');
});

it('replays operation events after the last seen sequence', function (): void {
    $otherRun = $this->operationRuns->queued((string) Str::uuid(), 'gateway', operationType: 'update:all');
    $this->events->step($otherRun, 'other', 'running');

    $first = $this->events->step($this->run, 'gateway', 'running');
    $second = $this->events->step($this->run, 'gateway', 'done');

    $events = $this->streamer->eventsAfter($this->run, $first->sequence);

    expect($first->id)->not->toBe($first->sequence)
        ->and($events)->toHaveCount(1)
        ->and($events->first()?->id)->toBe($second->id)
        ->and($events->first()?->sequence)->toBe(2);
});

it('detects terminal operation events', function (): void {
    $this->events->step($this->run, 'gateway', 'running');

    expect($this->streamer->hasTerminalEvent($this->run))->toBeFalse();

    $this->events->complete($this->run, 0);

    expect($this->streamer->hasTerminalEvent($this->run))->toBeTrue();
});

it('follows live operation events until a terminal event is written', function (): void {
    $this->events->step($this->run, 'gateway', 'running');

    $follow = $this->streamer->follow(
        operationRun: $this->run,
        pollMicroseconds: 0,
        maxIdlePolls: 2,
    );

    expect($follow->current())
        ->toBeInstanceOf(OperationEvent::class)
        ->event_type->toBe('step');

    $follow->next();

    expect($follow->current())->toBeNull();

    $terminal = $this->events->complete($this->run, 0, ['version' => '1.2.3']);

    $follow->next();

    expect($follow->current())
        ->toBeInstanceOf(OperationEvent::class)
        ->id->toBe($terminal->id);

    $follow->next();

    expect($follow->valid())->toBeFalse();
});
