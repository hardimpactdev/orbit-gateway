<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Schedules\OrbitScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('orbit-scheduler
    {--once : Run one scheduler tick and exit}
    {--max-ticks= : Stop after this many ticks}
    {--sleep-seconds= : Override daemon sleep interval between ticks}')]
#[Description('Run the Orbit Scheduler daemon')]
class OrbitSchedulerCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(OrbitScheduler $scheduler): int
    {
        $maxTicks = $this->maxTicks();
        $completedTicks = 0;

        do {
            try {
                $result = $scheduler->tick();
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $completedTicks++;

            $this->line(sprintf(
                'Orbit Scheduler tick completed at %s; due=%d executed=%d',
                $result->finishedAt->toIso8601String(),
                $result->dueSchedules,
                $result->executedSchedules,
            ));

            if ($this->option('once') === true || ($maxTicks !== null && $completedTicks >= $maxTicks)) {
                return self::SUCCESS;
            }

            sleep($this->sleepSeconds($scheduler));
        } while (true);
    }

    private function maxTicks(): ?int
    {
        $value = $this->option('max-ticks');

        if ($value === null) {
            return null;
        }

        $maxTicks = (int) $value;

        if ($maxTicks < 1) {
            return 1;
        }

        return $maxTicks;
    }

    private function sleepSeconds(OrbitScheduler $scheduler): int
    {
        $value = $this->option('sleep-seconds');

        if ($value === null) {
            return $scheduler->secondsUntilNextMinute();
        }

        return max(1, (int) $value);
    }
}
