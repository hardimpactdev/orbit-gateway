<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Actions\Deploy\AddDeployStep;
use App\Actions\Deploy\RemoveDeployStep;
use App\Contracts\AppRuntimeUserResolver;
use App\Contracts\ProgressReporter;
use App\Contracts\RemoteShell;
use App\Enums\Apps\AppRuntimeKind;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\DeploymentRun;
use App\Models\DeploymentRunStep;
use App\Models\DeployStep;
use App\Services\Apps\AppRuntimeUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final readonly class DeployManager
{
    public function __construct(
        private RemoteShell $remoteShell,
        private AppRuntimeUserResolver $appRuntimeUser = new AppRuntimeUser,
        private AddDeployStep $addDeployStep = new AddDeployStep,
        private RemoveDeployStep $removeDeployStep = new RemoveDeployStep,
    ) {}

    /**
     * @return array{step: array<string, mixed>, meta: array<string, mixed>}
     */
    public function addStep(string $app, string $command, ?string $title, ?int $order, int $timeout, ?int $retention): array
    {
        $model = $this->productionApp($app);

        $step = $this->addDeployStep->handle(
            appId: $model->id,
            title: $title ?? $this->titleFromCommand($command),
            command: $command,
            timeoutSeconds: $timeout,
            order: $order,
            retention: $retention,
        );

        return [
            'step' => $this->stepEntity($step),
            'meta' => ['action' => 'created'],
        ];
    }

    /**
     * @return array{steps: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listSteps(string $app): array
    {
        $model = $this->productionApp($app);
        $steps = DeployStep::query()
            ->where('app_id', $model->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (DeployStep $step): array => $this->stepEntity($step))
            ->values()
            ->all();

        return [
            'steps' => $steps,
            'meta' => [
                'app' => $model->name,
                'count' => count($steps),
            ],
        ];
    }

    /**
     * @return array{step: array<string, mixed>, meta: array<string, mixed>}
     */
    public function removeStep(string $app, string $selector): array
    {
        $model = $this->productionApp($app);
        $step = $this->findStep($model, $selector);

        if (! $step instanceof DeployStep) {
            throw new GatewayApiException(
                message: "Deployment step '{$selector}' was not found for app '{$model->name}'.",
                errorCode: 'deploy.step_not_found',
                errorMeta: ['app' => $model->name, 'step' => $selector],
            );
        }

        $entity = $this->stepEntity($step);
        $this->removeDeployStep->handle($step);

        return [
            'step' => $entity,
            'meta' => [
                'action' => 'removed',
                'history_preserved' => true,
            ],
        ];
    }

    /**
     * @return array{run: array<string, mixed>, output?: array{stdout: string, stderr: string}, meta: array<string, mixed>}
     */
    public function run(string $app, bool $detach = false, ?ProgressReporter $progress = null): array
    {
        $model = $this->productionApp($app)->loadMissing('node');
        $steps = DeployStep::query()
            ->where('app_id', $model->id)
            ->orderBy('sort_order')
            ->get();

        if ($steps->isEmpty()) {
            throw new GatewayApiException(
                message: "Deployment pipeline is empty for app '{$model->name}'.",
                errorCode: 'deploy.pipeline_empty',
                errorMeta: ['app' => $model->name],
            );
        }

        $progress?->tree('Running Deployment', $this->progressSteps($steps, $detach));
        $progress?->stepStart('resolve-app');
        $progress?->stepDone('resolve-app', $model->name);

        $startedAt = now();
        $progress?->stepStart('create-run');
        $run = DeploymentRun::query()->create([
            'app_id' => $model->id,
            'status' => 'running',
            'exit_code' => null,
            'started_at' => $startedAt,
        ]);
        $context = $this->runContext($model, $run, $startedAt);
        $run->forceFill(['context' => $context])->save();
        $progress?->stepDone('create-run', "#{$run->id}");

        $model->forceFill([
            'latest_deployment_status' => 'running',
            'latest_deployment_run_id' => $run->id,
        ])->save();

        if ($detach) {
            return [
                'run' => $this->runEntity($run),
                'meta' => [
                    'action' => 'started',
                    'detached' => true,
                ],
            ];
        }

        $stdout = '';
        $stderr = '';
        $status = 'completed';
        $exitCode = 0;

        foreach ($steps as $step) {
            $stepStartedAt = now();
            $command = $this->renderCommand($step->command, $context);
            $routedCommand = $this->routeCommand($model, $command, $context);
            $progress?->stepStart($this->progressKey($step));
            $result = $this->remoteShell->run($model->node ?? throw new GatewayApiException(
                message: "App '{$model->name}' has no owning node.",
                errorCode: 'deploy.execution_failed',
                errorMeta: ['app' => $model->name],
            ), $routedCommand, [
                'cwd' => $model->path,
                'timeout' => $step->timeout_seconds,
                'strict' => true,
                'metadata' => $this->environment($context),
            ]);
            $stepFinishedAt = now();
            $stepStatus = $result->successful() ? 'completed' : 'failed';
            $stdout .= $result->stdout;
            $stderr .= $result->stderr;

            DeploymentRunStep::query()->create([
                'deployment_run_id' => $run->id,
                'deploy_step_id' => $step->id,
                'title' => $step->title,
                'command' => $routedCommand,
                'status' => $stepStatus,
                'stdout' => $result->stdout,
                'stderr' => $result->stderr,
                'exit_code' => $result->exitCode,
                'started_at' => $stepStartedAt,
                'finished_at' => $stepFinishedAt,
                'duration_ms' => $result->durationMs,
            ]);

            if (! $result->successful()) {
                $progress?->stepFail($this->progressKey($step), "exit {$result->exitCode}");
                $status = 'failed';
                $exitCode = $result->exitCode;

                break;
            }

            $progress?->stepDone($this->progressKey($step), $this->formatDurationMs($result->durationMs));
        }

        if ($status === 'completed') {
            try {
                $warmupResult = $this->runWarmupSteps($model, $context, $progress);
                if ($warmupResult !== null) {
                    $stdout .= $warmupResult['stdout'];
                    $stderr .= $warmupResult['stderr'];
                }
            } catch (GatewayApiException $warmupException) {
                $status = 'failed';
                $exitCode = 1;
                $finishedAt = now();
                $run->forceFill([
                    'status' => $status,
                    'exit_code' => $exitCode,
                    'finished_at' => $finishedAt,
                    'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
                ])->save();
                $model->forceFill(['latest_deployment_status' => $status])->save();
                throw $warmupException;
            }
        }

        $finishedAt = now();
        $progress?->stepStart('record-result');
        $run->forceFill([
            'status' => $status,
            'exit_code' => $exitCode,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
        ])->save();

        $model->forceFill(['latest_deployment_status' => $status])->save();
        $run->load('steps');
        $progress?->stepDone('record-result', $status);

        $payload = [
            'run' => $this->runEntity($run),
            'output' => [
                'stdout' => $stdout,
                'stderr' => $stderr,
            ],
            'meta' => [
                'action' => $status,
                'duration_ms' => $run->duration_ms,
            ],
        ];

        if ($status === 'failed') {
            $failedStep = $run->steps->firstWhere('status', 'failed');

            throw new GatewayApiException(
                message: "Deployment step '{$failedStep?->title}' failed for app '{$model->name}'.",
                errorCode: 'deploy.step_failed',
                errorMeta: [
                    'app' => $model->name,
                    'step' => $failedStep?->title,
                    'duration_ms' => $run->duration_ms,
                ],
                errorData: [
                    'run' => $payload['run'],
                    'output' => $payload['output'],
                ],
            );
        }

        return $payload;
    }

    /**
     * Route a deploy step command through the host PHP toolchain when the app
     * is a PHP app and the command uses PHP, Composer, or Artisan.
     *
     * Non-PHP commands and non-PHP apps run on the host as-is.
     *
     * @param  array<string, mixed>  $context
     */
    private function routeCommand(App $app, string $command, array $context): string
    {
        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            return $command;
        }

        if (! $this->usesPhpTools($command)) {
            return $command;
        }

        return $this->wrapForHost($app, $command, $context);
    }

    /**
     * Detect whether a shell command invokes PHP, Composer, or Artisan.
     *
     * Excludes php-fpm and php\d+.\d+-fpm service commands, which are host
     * infrastructure operations and must not be routed into the container.
     */
    private function usesPhpTools(string $command): bool
    {
        $normalized = preg_replace('/[\'"].*?[\'"]/', '', $command);

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*php-fpm\b/', (string) $normalized) === 1) {
            return false;
        }

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*php\d+\.\d+-fpm\b/', (string) $normalized) === 1) {
            return false;
        }

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*php\s/', (string) $normalized) === 1) {
            return true;
        }

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*composer\s/', (string) $normalized) === 1) {
            return true;
        }

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*artisan\b/', (string) $normalized) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Wrap a PHP/Composer/Artisan command for host-side execution with the
     * version-matched PHP toolchain, running as the app runtime user.
     *
     * Shape:
     *   sudo -u <runtimeUser> -H bash -lc 'cd <appPath> && PATH=/opt/orbit/php/<ver>/bin:$PATH <command>'
     *
     * Environment variables are passed inline before the command inside the
     * inner shell string so they are visible to the child process.
     *
     * @param  array<string, mixed>  $context
     */
    private function wrapForHost(App $app, string $command, array $context): string
    {
        $appPath = rtrim((string) $app->path, '/');
        $phpVersion = $app->php_version;
        $runtimeUser = $this->appRuntimeUser->forApp($app);

        $envPrefix = '';

        foreach ($this->environment($context) as $key => $value) {
            $envPrefix .= "{$key}=".escapeshellarg($value).' ';
        }

        $inner = 'cd '.escapeshellarg($appPath)
            .' && PATH=/opt/orbit/php/'.escapeshellarg($phpVersion).'/bin:$PATH '
            .$envPrefix
            .$command;

        return implode(' ', array_map(escapeshellarg(...), ['sudo', '-u', $runtimeUser, '-H', 'bash', '-lc', $inner]));
    }

    /**
     * Run built-in production warmup steps for PHP apps on the host using the
     * version-matched PHP toolchain. Returns captured output when warmups run,
     * or null when skipped.
     *
     * Warmup failures are caught and surfaced as deploy.warmup_failed so the
     * run status is updated to failed rather than left stuck at running.
     *
     * @param  array<string, mixed>  $context
     * @return array{stdout: string, stderr: string}|null
     */
    private function runWarmupSteps(App $app, array $context, ?ProgressReporter $progress = null): ?array
    {
        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            return null;
        }

        $node = $app->node;

        if ($node === null) {
            return null;
        }

        $warmupCommands = [
            'composer install --no-dev --optimize-autoloader --no-interaction',
            'php artisan optimize',
        ];

        $stdout = '';
        $stderr = '';

        foreach ($warmupCommands as $warmupCommand) {
            $routedCommand = $this->wrapForHost($app, $warmupCommand, $context);

            $result = $this->remoteShell->run($node, $routedCommand, [
                'cwd' => $app->path,
                'timeout' => 300,
                'strict' => true,
                'metadata' => $this->environment($context),
            ]);

            $stdout .= $result->stdout;
            $stderr .= $result->stderr;

            if (! $result->successful()) {
                throw new GatewayApiException(
                    message: "Deployment warmup step '{$warmupCommand}' failed for app '{$app->name}'.",
                    errorCode: 'deploy.warmup_failed',
                    errorMeta: [
                        'app' => $app->name,
                        'warmup_command' => $warmupCommand,
                    ],
                    errorData: [
                        'stdout' => $stdout,
                        'stderr' => $stderr,
                    ],
                );
            }
        }

        $this->runHttpWarmup($app, $context);

        return ['stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Send HTTP warmup requests to the app when warmup paths are configured.
     *
     * Requests are sent via docker exec into the FrankenPHP container that
     * still serves the app's HTTP traffic. The PHP toolchain migration does
     * not affect the serving container.
     *
     * @param  array<string, mixed>  $context
     */
    private function runHttpWarmup(App $app, array $context): void
    {
        $warmupPaths = $app->deploy_warmup_paths ?? [];

        if ($warmupPaths === []) {
            return;
        }

        $node = $app->node;

        if ($node === null) {
            return;
        }

        $containerName = "orbit-app-{$app->name}";

        foreach ($warmupPaths as $path) {
            $command = sprintf(
                'docker exec %s curl -sSf http://localhost%s',
                escapeshellarg($containerName),
                escapeshellarg((string) $path),
            );

            $this->remoteShell->run($node, $command, [
                'cwd' => $app->path,
                'timeout' => 30,
                'strict' => false,
                'metadata' => $this->environment($context),
            ]);
        }
    }

    /**
     * @param  Collection<int, DeployStep>  $steps
     * @return list<array{key: string, label: string, doneLabel?: string}>
     */
    private function progressSteps(Collection $steps, bool $detach): array
    {
        $progressSteps = [
            [
                'key' => 'resolve-app',
                'label' => 'Resolve production app',
                'doneLabel' => 'Resolved production app',
            ],
            [
                'key' => 'create-run',
                'label' => 'Create deployment run',
                'doneLabel' => 'Created deployment run',
            ],
        ];

        if ($detach) {
            return $progressSteps;
        }

        foreach ($steps as $step) {
            $progressSteps[] = [
                'key' => $this->progressKey($step),
                'label' => $step->title,
                'doneLabel' => $step->title,
            ];
        }

        $progressSteps[] = [
            'key' => 'record-result',
            'label' => 'Record deployment result',
            'doneLabel' => 'Recorded deployment result',
        ];

        return $progressSteps;
    }

    private function progressKey(DeployStep $step): string
    {
        return "deploy-step-{$step->id}";
    }

    private function formatDurationMs(int $durationMs): string
    {
        if ($durationMs < 1000) {
            return "{$durationMs}ms";
        }

        return number_format($durationMs / 1000, 1).'s';
    }

    /**
     * @return array{runs: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function history(string $app, int $limit): array
    {
        $model = $this->productionApp($app);
        $effectiveLimit = min($limit, 500);
        $total = DeploymentRun::query()->where('app_id', $model->id)->count();
        $runs = DeploymentRun::query()
            ->with('steps')
            ->where('app_id', $model->id)
            ->orderByDesc('started_at')
            ->limit($effectiveLimit)
            ->get()
            ->map(fn (DeploymentRun $run): array => $this->runEntity($run))
            ->values()
            ->all();

        return [
            'runs' => $runs,
            'meta' => [
                'pagination' => [
                    'total' => $total,
                    'limit' => $effectiveLimit,
                    'limit_capped' => $limit > 500,
                ],
            ],
        ];
    }

    /**
     * @return array{run: array<string, mixed>, steps: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function log(string $app, int $runId, ?int $stepId, int $lines): array
    {
        $model = $this->productionApp($app);
        $run = DeploymentRun::query()
            ->with('steps')
            ->where('app_id', $model->id)
            ->whereKey($runId)
            ->first();

        if (! $run instanceof DeploymentRun) {
            throw new GatewayApiException(
                message: "Deployment run {$runId} was not found for app '{$model->name}'.",
                errorCode: 'deploy.run_not_found',
                errorMeta: ['app' => $model->name, 'run' => $runId],
            );
        }

        $steps = $run->steps;

        if ($stepId !== null) {
            $steps = $steps->where('id', $stepId)->values();

            if ($steps->isEmpty()) {
                throw new GatewayApiException(
                    message: "Deployment step {$stepId} was not found in run {$runId} for app '{$model->name}'.",
                    errorCode: 'deploy.step_not_found',
                    errorMeta: ['app' => $model->name, 'run' => $runId, 'step' => $stepId],
                );
            }
        }

        $truncated = false;
        $entities = $steps
            ->map(function (DeploymentRunStep $step) use ($lines, &$truncated): array {
                $stdout = $this->tailLines((string) $step->stdout, $lines);
                $stderr = $this->tailLines((string) $step->stderr, $lines);
                $truncated = $truncated
                    || $stdout !== (string) $step->stdout
                    || $stderr !== (string) $step->stderr;

                return $this->runStepLogEntity($step, $stdout, $stderr);
            })
            ->values()
            ->all();

        return [
            'run' => $this->runEntity($run),
            'steps' => $entities,
            'meta' => [
                'lines' => $lines,
                'truncated_by_filter' => $truncated,
            ],
        ];
    }

    public function productionApp(string $selector): App
    {
        $app = App::query()
            ->with('node')
            ->where('name', $selector)
            ->orWhere('domain', $selector)
            ->first();

        if (! $app instanceof App) {
            throw new GatewayApiException(
                message: "App '{$selector}' was not found.",
                errorCode: 'app.not_found',
                errorMeta: ['app' => $selector],
            );
        }

        if ($app->environment !== 'production') {
            throw new GatewayApiException(
                message: "App '{$app->name}' is not a production app.",
                errorCode: 'deploy.production_app_required',
                errorMeta: [
                    'app' => $app->name,
                    'environment' => $app->environment,
                ],
            );
        }

        return $app;
    }

    public function stepEntity(DeployStep $step): array
    {
        $step->loadMissing('app');

        return [
            'id' => $step->id,
            'app' => $step->app?->name,
            'title' => $step->title,
            'command' => $step->command,
            'order' => $step->sort_order,
            'timeout_seconds' => $step->timeout_seconds,
            'retention' => $step->retention,
        ];
    }

    public function runEntity(DeploymentRun $run): array
    {
        $run->loadMissing('app', 'steps');

        return [
            'id' => $run->id,
            'app' => $run->app?->name,
            'status' => $run->status,
            'exit_code' => $run->exit_code,
            'started_at' => $run->started_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
            'context' => $run->context ?? [],
            'steps' => $run->steps
                ->map(fn (DeploymentRunStep $step): array => [
                    'id' => $step->id,
                    'title' => $step->title,
                    'status' => $step->status,
                    'exit_code' => $step->exit_code,
                ])
                ->values()
                ->all(),
        ];
    }

    private function runStepLogEntity(DeploymentRunStep $step, string $stdout, string $stderr): array
    {
        return [
            'id' => $step->id,
            'title' => $step->title,
            'status' => $step->status,
            'exit_code' => $step->exit_code,
            'started_at' => $step->started_at?->toJSON(),
            'finished_at' => $step->finished_at?->toJSON(),
            'output' => [
                'stdout' => $stdout,
                'stderr' => $stderr,
            ],
        ];
    }

    private function findStep(App $app, string $selector): ?DeployStep
    {
        $query = DeployStep::query()->where('app_id', $app->id);

        if (ctype_digit($selector)) {
            return (clone $query)->whereKey((int) $selector)->first();
        }

        return $query->where('title', $selector)->first();
    }

    private function titleFromCommand(string $command): string
    {
        $title = trim($command);

        return strlen($title) > 60 ? substr($title, 0, 57).'...' : $title;
    }

    private function tailLines(string $value, int $lines): string
    {
        $parts = preg_split("/\r\n|\n|\r/", rtrim($value, "\r\n"));

        if (! is_array($parts) || $parts === ['']) {
            return $value;
        }

        if (count($parts) <= $lines) {
            return $value;
        }

        return implode("\n", array_slice($parts, -$lines))."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function runContext(App $app, DeploymentRun $run, Carbon $startedAt): array
    {
        $app->loadMissing('node');

        $appPath = rtrim($app->path, '/');
        $release = $startedAt->copy()->utc()->format('Ymd_His').'_'.$run->id;
        $appUser = $this->appUser($app);

        return [
            'app_name' => $app->name,
            'app_path' => $appPath,
            'app_user' => $appUser,
            'domain' => $app->domain,
            'repository' => $app->repository,
            'release' => $release,
            'release_name' => $release,
            'releases_path' => "{$appPath}/releases",
            'release_path' => "{$appPath}/releases/{$release}",
            'live_path' => "{$appPath}/live",
            'env_path' => "{$appPath}/.env",
            'storage_path' => "{$appPath}/storage",
            'database_path' => "{$appPath}/database/database.sqlite",
            'app' => [
                'name' => $app->name,
                'path' => $appPath,
                'user' => $appUser,
                'domain' => $app->domain,
                'repository' => $app->repository,
            ],
            'node' => [
                'name' => $app->node?->name,
                'host' => $app->node?->host,
                'user' => $app->node?->user ?: 'orbit',
            ],
        ];
    }

    private function appUser(App $app): string
    {
        return $this->appRuntimeUser->forApp($app);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderCommand(string $command, array $context): string
    {
        return preg_replace_callback('/{{\s*([A-Za-z0-9_.-]+)\s*}}/', function (array $matches) use ($context): string {
            $value = Arr::get($context, $matches[1]);

            if (is_scalar($value) || $value === null) {
                return (string) $value;
            }

            return $matches[0];
        }, $command) ?? $command;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    private function environment(array $context): array
    {
        $environment = [];

        foreach ($context as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $environment['ORBIT_DEPLOY_'.Str::upper((string) $key)] = (string) $value;
        }

        return $environment;
    }
}
