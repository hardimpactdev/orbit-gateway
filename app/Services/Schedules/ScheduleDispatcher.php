<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Data\RemoteShell\RemoteShellPoolJob;
use App\Data\RemoteShell\RemoteShellPoolResult;
use App\Data\RemoteShell\RemoteShellResult;
use App\Data\Schedules\ScheduleDispatchResult;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleRun;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\RemoteShell\RemoteShellPool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Throwable;

final readonly class ScheduleDispatcher
{
    private const int DEFAULT_TIMEOUT = 900;

    public function __construct(
        private RemoteShellPool $remoteShellPool,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function run(Schedule $schedule): ScheduleDispatchResult
    {
        return $this->runMany([$schedule])[0];
    }

    /**
     * @param  list<Schedule>  $schedules
     * @return list<ScheduleDispatchResult>
     */
    public function runMany(array $schedules): array
    {
        if ($schedules === []) {
            return [];
        }

        $resultsByIndex = [];
        $remoteJobs = [];
        $remoteContexts = [];

        foreach (array_values($schedules) as $index => $schedule) {
            $schedule->loadMissing(['app.node', 'node']);

            $targetNode = $this->targetNode($schedule);

            if (! $targetNode instanceof Node) {
                $message = "Schedule '{$schedule->name}' does not resolve to a target node.";

                if (($gatewayNode = $this->gatewayNode()) instanceof Node) {
                    $resultsByIndex[$index] = $this->recordDispatchFailure($schedule, $gatewayNode, $message);

                    continue;
                }

                throw new GatewayApiException($message, 'validation_failed', [
                    'field' => 'target',
                    'schedule' => $schedule->name,
                ]);
            }

            if ($this->isGatewayNode($targetNode)) {
                $resultsByIndex[$index] = $this->runLocallyAndRecord($schedule, $targetNode);

                continue;
            }

            $jobKey = (string) $index;
            $remoteJobs[] = new RemoteShellPoolJob(
                key: $jobKey,
                node: $targetNode,
                script: $this->executionScript($schedule),
                options: $this->executionOptions($schedule),
            );
            $remoteContexts[$jobKey] = [
                'index' => $index,
                'schedule' => $schedule,
                'target_node' => $targetNode,
                'started_at' => CarbonImmutable::now(),
            ];
        }

        foreach ($this->remoteShellPool->run($remoteJobs) as $remoteResult) {
            /** @var array{index: int, schedule: Schedule, target_node: Node, started_at: CarbonImmutable} $context */
            $context = $remoteContexts[$remoteResult->key];
            $resultsByIndex[$context['index']] = $this->recordRemotePoolResult(
                poolResult: $remoteResult,
                schedule: $context['schedule'],
                targetNode: $context['target_node'],
                startedAt: $context['started_at'],
            );
        }

        ksort($resultsByIndex);

        return array_values($resultsByIndex);
    }

    private function runLocallyAndRecord(Schedule $schedule, Node $targetNode): ScheduleDispatchResult
    {
        $startedAt = CarbonImmutable::now();
        $startedHrtime = hrtime(true);

        try {
            $result = $this->runLocally($schedule);
        } catch (Throwable $throwable) {
            $finishedAt = CarbonImmutable::now();
            $durationMs = (int) ((hrtime(true) - $startedHrtime) / 1_000_000);

            return new ScheduleDispatchResult(
                run: $this->recordRun(
                    schedule: $schedule,
                    targetNode: $targetNode,
                    status: 'failed',
                    exitCode: 1,
                    stdout: '',
                    stderr: $throwable->getMessage(),
                    startedAt: $startedAt,
                    finishedAt: $finishedAt,
                ),
                targetNode: $targetNode,
                durationMs: $durationMs,
            );
        }

        $finishedAt = CarbonImmutable::now();

        return new ScheduleDispatchResult(
            run: $this->recordRun(
                schedule: $schedule,
                targetNode: $targetNode,
                status: $result->successful() ? 'completed' : 'failed',
                exitCode: $result->exitCode,
                stdout: $result->stdout,
                stderr: $result->stderr,
                startedAt: $startedAt,
                finishedAt: $finishedAt,
            ),
            targetNode: $targetNode,
            durationMs: $result->durationMs,
        );
    }

    private function recordRemotePoolResult(
        RemoteShellPoolResult $poolResult,
        Schedule $schedule,
        Node $targetNode,
        CarbonImmutable $startedAt,
    ): ScheduleDispatchResult {
        $finishedAt = CarbonImmutable::now();

        if ($poolResult->exception instanceof Throwable) {
            return new ScheduleDispatchResult(
                run: $this->recordRun(
                    schedule: $schedule,
                    targetNode: $targetNode,
                    status: 'failed',
                    exitCode: 1,
                    stdout: '',
                    stderr: $poolResult->exception->getMessage(),
                    startedAt: $startedAt,
                    finishedAt: $finishedAt,
                ),
                targetNode: $targetNode,
                durationMs: 0,
            );
        }

        $result = $poolResult->result ?? new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: 'Remote shell pool did not return a result.',
            durationMs: 0,
        );

        return new ScheduleDispatchResult(
            run: $this->recordRun(
                schedule: $schedule,
                targetNode: $targetNode,
                status: $result->successful() ? 'completed' : 'failed',
                exitCode: $result->exitCode,
                stdout: $result->stdout,
                stderr: $result->stderr,
                startedAt: $startedAt,
                finishedAt: $finishedAt,
            ),
            targetNode: $targetNode,
            durationMs: $result->durationMs,
        );
    }

    private function targetNode(Schedule $schedule): ?Node
    {
        if ($schedule->scope === 'app') {
            return $schedule->app?->node;
        }

        if ($schedule->scope === 'node') {
            return $schedule->node;
        }

        if ($schedule->scope === 'orbit') {
            return $this->gatewayNode();
        }

        return null;
    }

    private function gatewayNode(): ?Node
    {
        return $this->nodeRoleAssignments
            ->activeGatewayNodeQuery()
            ->first();
    }

    private function isGatewayNode(Node $node): bool
    {
        return $this->nodeRoleAssignments->nodeIsGateway($node);
    }

    private function runLocally(Schedule $schedule): RemoteShellResult
    {
        $pendingProcess = Process::timeout(self::DEFAULT_TIMEOUT);
        $options = $this->executionOptions($schedule);

        if (isset($options['cwd']) && $options['cwd'] !== '') {
            $pendingProcess = $pendingProcess->path($options['cwd']);
        }

        $startedAt = hrtime(true);
        $processResult = $pendingProcess->run($this->executionScript($schedule));
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        return new RemoteShellResult(
            exitCode: $processResult->exitCode() ?? 1,
            stdout: $processResult->output(),
            stderr: $processResult->errorOutput(),
            durationMs: $durationMs,
        );
    }

    /**
     * @return array{cwd?: string, timeout: int}
     */
    private function executionOptions(Schedule $schedule): array
    {
        $options = ['timeout' => self::DEFAULT_TIMEOUT];

        if ($schedule->scope === 'app' && $schedule->app !== null && $schedule->app->path !== '') {
            $options['cwd'] = $schedule->app->path;
        }

        return $options;
    }

    private function executionScript(Schedule $schedule): string
    {
        if ($schedule->execution_type === 'script') {
            return escapeshellarg($schedule->execution_value);
        }

        return $schedule->execution_value;
    }

    private function recordDispatchFailure(Schedule $schedule, Node $targetNode, string $message): ScheduleDispatchResult
    {
        $startedAt = CarbonImmutable::now();

        return new ScheduleDispatchResult(
            run: $this->recordRun(
                schedule: $schedule,
                targetNode: $targetNode,
                status: 'failed',
                exitCode: 1,
                stdout: '',
                stderr: $message,
                startedAt: $startedAt,
                finishedAt: CarbonImmutable::now(),
            ),
            targetNode: $targetNode,
            durationMs: 0,
        );
    }

    private function recordRun(
        Schedule $schedule,
        Node $targetNode,
        string $status,
        int $exitCode,
        string $stdout,
        string $stderr,
        CarbonImmutable $startedAt,
        CarbonImmutable $finishedAt,
    ): ScheduleRun {
        return ScheduleRun::query()->create([
            'node_id' => $targetNode->id,
            'schedule_key' => $schedule->schedule_key,
            'status' => $status,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
    }
}
