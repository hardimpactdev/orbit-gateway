<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Contracts\RemoteShell;
use App\Contracts\StartsRemoteShellProcesses;
use App\Data\RemoteShell\RemoteShellPoolJob;
use App\Data\RemoteShell\RemoteShellPoolResult;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\RemoteShellFailed;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Throwable;

final readonly class RemoteShellPool
{
    private const int DefaultConcurrency = 4;

    private const int PollIntervalMicroseconds = 50_000;

    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @param  list<RemoteShellPoolJob>  $jobs
     * @return list<RemoteShellPoolResult>
     */
    public function run(array $jobs, int $concurrency = self::DefaultConcurrency): array
    {
        if ($jobs === []) {
            return [];
        }

        $concurrency = max(1, $concurrency);

        if (! $this->remoteShell instanceof StartsRemoteShellProcesses || $concurrency === 1) {
            return $this->runSequentially($jobs);
        }

        return $this->runConcurrently($jobs, $concurrency, $this->remoteShell);
    }

    /**
     * @param  list<RemoteShellPoolJob>  $jobs
     * @return list<RemoteShellPoolResult>
     */
    private function runSequentially(array $jobs): array
    {
        $results = [];

        foreach ($jobs as $job) {
            try {
                $results[] = new RemoteShellPoolResult(
                    key: $job->key,
                    job: $job,
                    result: $this->remoteShell->run($job->node, $job->script, $job->options),
                );
            } catch (Throwable $throwable) {
                $results[] = new RemoteShellPoolResult(
                    key: $job->key,
                    job: $job,
                    exception: $throwable,
                );
            }
        }

        return $results;
    }

    /**
     * @param  list<RemoteShellPoolJob>  $jobs
     * @return list<RemoteShellPoolResult>
     */
    private function runConcurrently(array $jobs, int $concurrency, StartsRemoteShellProcesses $remoteShell): array
    {
        $workers = [];
        $resultsByIndex = [];
        $nextIndex = 0;

        while ($nextIndex < count($jobs) || $workers !== []) {
            while (count($workers) < $concurrency && $nextIndex < count($jobs)) {
                $job = $jobs[$nextIndex];
                $startedAt = hrtime(true);

                try {
                    $workers[$nextIndex] = [
                        'job' => $job,
                        'process' => $remoteShell->start($job->node, $job->script, $job->options),
                        'started_at' => $startedAt,
                    ];
                } catch (Throwable $throwable) {
                    $resultsByIndex[$nextIndex] = new RemoteShellPoolResult(
                        key: $job->key,
                        job: $job,
                        exception: $throwable,
                    );
                }

                $nextIndex++;
            }

            foreach (array_keys($workers) as $index) {
                /** @var array{job: RemoteShellPoolJob, process: InvokedProcess, started_at: int} $worker */
                $worker = $workers[$index];
                $process = $worker['process'];

                try {
                    if (method_exists($process, 'ensureNotTimedOut')) {
                        $process->ensureNotTimedOut();
                    }

                    if ($process->running()) {
                        continue;
                    }

                    $resultsByIndex[$index] = $this->resultFromProcess(
                        job: $worker['job'],
                        process: $process,
                        startedAt: $worker['started_at'],
                    );
                } catch (ProcessTimedOutException $exception) {
                    $resultsByIndex[$index] = $this->resultFromTimeout(
                        job: $worker['job'],
                        exception: $exception,
                        startedAt: $worker['started_at'],
                    );
                } catch (Throwable $throwable) {
                    $resultsByIndex[$index] = new RemoteShellPoolResult(
                        key: $worker['job']->key,
                        job: $worker['job'],
                        exception: $throwable,
                    );
                }

                unset($workers[$index]);
            }

            if ($workers !== []) {
                usleep(self::PollIntervalMicroseconds);
            }
        }

        ksort($resultsByIndex);

        return array_values($resultsByIndex);
    }

    private function resultFromProcess(
        RemoteShellPoolJob $job,
        InvokedProcess $process,
        int $startedAt,
    ): RemoteShellPoolResult {
        try {
            $result = $process->wait();
        } catch (ProcessTimedOutException $exception) {
            return $this->resultFromTimeout($job, $exception, $startedAt);
        } catch (Throwable $throwable) {
            return new RemoteShellPoolResult(
                key: $job->key,
                job: $job,
                exception: $throwable,
            );
        }

        return $this->resultFromRemoteShellResult(
            job: $job,
            result: new RemoteShellResult(
                exitCode: $result->exitCode() ?? 1,
                stdout: $result->output(),
                stderr: $result->errorOutput(),
                durationMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ),
        );
    }

    private function resultFromTimeout(
        RemoteShellPoolJob $job,
        ProcessTimedOutException $exception,
        int $startedAt,
    ): RemoteShellPoolResult {
        return $this->resultFromRemoteShellResult(
            job: $job,
            result: new RemoteShellResult(
                exitCode: $exception->result->exitCode() ?? 1,
                stdout: $exception->result->output(),
                stderr: $exception->result->errorOutput(),
                durationMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ),
        );
    }

    private function resultFromRemoteShellResult(
        RemoteShellPoolJob $job,
        RemoteShellResult $result,
    ): RemoteShellPoolResult {
        if ((bool) ($job->options['throw'] ?? false) && ! $result->successful()) {
            return new RemoteShellPoolResult(
                key: $job->key,
                job: $job,
                exception: new RemoteShellFailed($job->node, $job->script, $result),
            );
        }

        return new RemoteShellPoolResult(
            key: $job->key,
            job: $job,
            result: $result,
        );
    }
}
