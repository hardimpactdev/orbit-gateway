<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationEvent;
use App\Models\OperationRun;
use Generator;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

final readonly class OperationEventStreamer
{
    /**
     * @return Collection<int, OperationEvent>
     */
    public function eventsAfter(OperationRun|string $operationRun, ?int $lastSequence = null): Collection
    {
        $operationRun = $this->findOrFail($operationRun);

        return OperationEvent::query()
            ->where('operation_run_id', $operationRun->id)
            ->when($lastSequence !== null, fn ($query) => $query->where('sequence', '>', $lastSequence))
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    public function hasTerminalEvent(OperationRun|string $operationRun): bool
    {
        $operationRun = $this->findOrFail($operationRun);

        return OperationEvent::query()
            ->where('operation_run_id', $operationRun->id)
            ->whereIn('event_type', ['complete', 'error'])
            ->exists();
    }

    /**
     * @return Generator<int, OperationEvent|null>
     */
    public function follow(
        OperationRun|string $operationRun,
        ?int $lastSequence = null,
        int $pollMicroseconds = 500_000,
        ?int $maxIdlePolls = null,
    ): Generator {
        $operationRun = $this->findOrFail($operationRun);
        $idlePolls = 0;

        while (true) {
            $events = $this->eventsAfter($operationRun, $lastSequence);

            if ($events->isNotEmpty()) {
                foreach ($events as $event) {
                    $lastSequence = $event->sequence;

                    yield $event;
                }

                $idlePolls = 0;

                continue;
            }

            if ($this->hasTerminalEvent($operationRun)) {
                return;
            }

            if ($maxIdlePolls !== null && $idlePolls >= $maxIdlePolls) {
                return;
            }

            $idlePolls++;

            yield null;

            if ($pollMicroseconds > 0) {
                usleep($pollMicroseconds);
            }
        }
    }

    private function findOrFail(OperationRun|string $operationRun): OperationRun
    {
        if ($operationRun instanceof OperationRun) {
            return $operationRun;
        }

        $run = OperationRun::query()->find($operationRun);

        if ($run === null) {
            throw new RuntimeException("OperationRun {$operationRun} not found.");
        }

        return $run;
    }
}
