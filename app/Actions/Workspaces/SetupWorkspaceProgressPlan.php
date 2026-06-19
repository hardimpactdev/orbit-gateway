<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Contracts\ProgressReporter;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use RuntimeException;
use Throwable;

final class SetupWorkspaceProgressPlan
{
    /** @var list<array<string, string>> */
    private array $warnings = [];

    /** @var array{status: string, message: string, count: int} */
    private array $setupResult = [
        'status' => 'skipped',
        'message' => 'No setup steps configured',
        'count' => 0,
    ];

    /** @var array{success: bool, message: string, count: int, names: list<string>} */
    private array $processResult = [
        'success' => true,
        'message' => 'No processes',
        'count' => 0,
        'names' => [],
    ];

    /** @var array{reachable: bool, status: string} */
    private array $httpProbe = [
        'reachable' => false,
        'status' => 'not_run',
    ];

    /** @var array{code: string, message: string, meta: array<string, mixed>}|null */
    private ?array $failure = null;

    private readonly bool $wasAlreadyActive;

    private ?ProgressReporter $reporter = null;

    public function __construct(
        private readonly SetupWorkspace $setupWorkspace,
        private readonly Workspace $workspace,
        private readonly App $app,
        private readonly Node $node,
        private readonly bool $isAdoption,
    ) {
        $this->wasAlreadyActive = $workspace->lifecycle_status === WorkspaceLifecycleStatus::Active;
    }

    public function title(): string
    {
        return 'Setting Up Workspace';
    }

    /**
     * @return list<array{key: string, label: string, doneLabel: string, run: callable(): string}>
     */
    public function steps(): array
    {
        $steps = [
            [
                'key' => 'apply_workspace_registration',
                'label' => 'Apply and verify workspace registration',
                'doneLabel' => 'Applied and verified workspace registration',
                'run' => function (): string {
                    $this->setupWorkspace->prepareWorkspaceState($this->workspace);

                    return $this->workspace->name;
                },
            ],
            [
                'key' => 'register_proxy_routes',
                'label' => 'Register proxy routes',
                'doneLabel' => 'Registered proxy routes',
                'run' => function (): string {
                    $routeWarnings = $this->setupWorkspace->registerProxyRoutes($this->workspace);
                    $this->warnings = array_merge($this->warnings, $routeWarnings);

                    if ($routeWarnings !== []) {
                        return 'skip:'.(string) ($routeWarnings[0]['message'] ?? 'Proxy route requires convergence.');
                    }

                    return 'ready';
                },
            ],
            [
                'key' => 'install_workspace_runtime_container',
                'label' => 'Install workspace runtime container',
                'doneLabel' => 'Installed workspace runtime container',
                'run' => function (): string {
                    $warning = $this->setupWorkspace->enactRuntimeContainer($this->workspace, $this->node);

                    if ($warning !== null) {
                        $this->warnings[] = $warning;

                        return 'skip:'.$warning['message'];
                    }

                    return 'ready';
                },
            ],
        ];

        if ($this->hasSetupSteps()) {
            $steps[] = [
                'key' => 'run_workspace_setup_steps',
                'label' => 'Run workspace setup steps',
                'doneLabel' => 'Ran workspace setup steps',
                'run' => function (): string {
                    $this->setupResult = $this->setupWorkspace->runSetupSteps(
                        $this->workspace,
                        $this->app,
                        $this->node,
                        $this->reportSetupStepProgress(...),
                    );

                    if ($this->setupResult['status'] === 'failed') {
                        $this->failure = [
                            'code' => 'workspace.setup_step_failed',
                            'message' => $this->setupResult['message'],
                            'meta' => [
                                'phase' => 'setup_steps',
                                'node' => $this->node->name,
                                'path' => $this->workspace->path,
                            ],
                        ];

                        throw new RuntimeException($this->setupResult['message']);
                    }

                    return $this->setupResult['message'];
                },
            ];
        }

        if ($this->hasProcesses()) {
            $steps[] = [
                'key' => 'render_inherited_runtime_units',
                'label' => 'Render inherited runtime units',
                'doneLabel' => 'Rendered inherited runtime units',
                'run' => function (): string {
                    $this->processResult = $this->setupWorkspace->startProcesses($this->app, $this->workspace, $this->node);

                    if (! $this->processResult['success']) {
                        $this->failure = [
                            'code' => 'workspace.enactment_failed',
                            'message' => $this->processResult['message'],
                            'meta' => [
                                'phase' => 'process',
                                'node' => $this->node->name,
                            ],
                        ];

                        throw new RuntimeException($this->processResult['message']);
                    }

                    return $this->processResult['message'];
                },
            ];
        }

        $steps[] = [
            'key' => 'check_workspace_readiness',
            'label' => 'Check workspace readiness',
            'doneLabel' => 'Checked workspace readiness',
            'run' => function (): string {
                $this->httpProbe = $this->setupWorkspace->probeReadiness($this->workspace);
                $this->setupWorkspace->markActive($this->workspace);

                if (! $this->httpProbe['reachable']) {
                    $warning = [
                        'code' => 'workspace.http_probe_unhealthy',
                        'family' => 'workspace',
                        'message' => "Workspace did not become reachable: {$this->httpProbe['status']}",
                        'next_command' => 'doctor --family=workspace --restore',
                    ];
                    $this->warnings[] = $warning;

                    return 'skip:'.$warning['message'];
                }

                return $this->httpProbe['status'];
            },
        ];

        return $steps;
    }

    public function runForReporter(ProgressReporter $reporter): int
    {
        $this->reporter = $reporter;
        $steps = $this->steps();

        $reporter->tree($this->title(), array_map(static fn (array $step): array => [
            'key' => $step['key'],
            'label' => $step['label'],
            'doneLabel' => $step['doneLabel'],
        ], $steps));

        foreach ($steps as $step) {
            $reporter->stepStart($step['key']);

            try {
                $message = (string) ($step['run'])();
            } catch (Throwable $e) {
                $this->failure ??= [
                    'code' => 'workspace.enactment_failed',
                    'message' => $e->getMessage(),
                    'meta' => [
                        'phase' => 'artifacts',
                        'node' => $this->node->name,
                    ],
                ];
                $reporter->stepFail($step['key'], $e->getMessage());

                $this->reporter = null;

                return 1;
            }

            if (str_starts_with($message, 'skip:')) {
                $reporter->stepSkip($step['key'], substr($message, 5));

                continue;
            }

            if (str_starts_with($message, 'fail:')) {
                $this->failure ??= [
                    'code' => 'workspace.enactment_failed',
                    'message' => substr($message, 5),
                    'meta' => [
                        'phase' => 'artifacts',
                        'node' => $this->node->name,
                    ],
                ];
                $reporter->stepFail($step['key'], substr($message, 5));

                return 1;
            }

            $reporter->stepDone($step['key'], $message === '' ? null : $message);
        }

        $this->reporter = null;

        return 0;
    }

    private function reportSetupStepProgress(string $event, WorkspaceStep $step, int $index, int $count): void
    {
        if ($this->reporter === null) {
            return;
        }

        $command = str($step->command)->squish()->limit(80)->toString();
        $message = match ($event) {
            'completed' => "Completed setup step {$index}/{$count}: {$command}",
            'failed' => "Failed setup step {$index}/{$count}: {$command}",
            default => "Running setup step {$index}/{$count}: {$command}",
        };

        $this->reporter->stepProgress('run_workspace_setup_steps', 'progress', $message);
    }

    public function doneFooter(): string
    {
        return "Workspace ready and available at: {$this->workspace->url()}";
    }

    public function failFooter(): string
    {
        return "Failed to set up workspace '{$this->workspace->name}'.";
    }

    /**
     * @return 'set_up'|'adopted'|'converged'
     */
    public function action(): string
    {
        if ($this->isAdoption) {
            return 'adopted';
        }

        if ($this->wasAlreadyActive) {
            return 'converged';
        }

        return 'set_up';
    }

    /**
     * @return array{code: string, message: string, meta: array<string, mixed>}|null
     */
    public function failure(): ?array
    {
        return $this->failure;
    }

    /**
     * @return array{
     *     app: string,
     *     workspace: string,
     *     node: string,
     *     path: string,
     *     url: string,
     *     action: 'set_up'|'adopted'|'converged',
     *     warnings: list<array<string, string>>,
     *     setup_steps: array{status: string, message: string, count: int},
     *     processes: array{status: string, message: string, count: int, names: list<string>},
     *     http_probe: array{reachable: bool, status: string},
     * }
     */
    public function result(): array
    {
        return [
            'app' => $this->app->name,
            'workspace' => $this->workspace->name,
            'node' => $this->node->name,
            'path' => $this->workspace->path,
            'url' => $this->workspace->url(),
            'action' => $this->action(),
            'warnings' => $this->warnings,
            'setup_steps' => $this->setupResult,
            'processes' => [
                'status' => 'started',
                'count' => $this->processResult['count'],
                'names' => $this->processResult['names'],
                'message' => $this->processResult['message'],
            ],
            'http_probe' => $this->httpProbe,
        ];
    }

    private function hasSetupSteps(): bool
    {
        return WorkspaceStep::query()
            ->where('app_id', $this->app->id)
            ->where('phase', WorkspaceLifecyclePhase::Setup)
            ->exists();
    }

    private function hasProcesses(): bool
    {
        return Process::query()
            ->where('app_id', $this->app->id)
            ->exists();
    }
}
