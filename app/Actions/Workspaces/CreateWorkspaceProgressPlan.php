<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Contracts\ProgressReporter;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use RuntimeException;
use Throwable;

final class CreateWorkspaceProgressPlan
{
    private ?Workspace $workspace = null;

    /** @var list<array<string, string>> */
    private array $warnings = [];

    /** @var array{reachable: bool, status: string} */
    private array $httpProbe = [
        'reachable' => false,
        'status' => 'not_run',
    ];

    /** @var array{code: string, message: string, meta: array<string, mixed>}|null */
    private ?array $failure = null;

    private bool $workspaceSourceProvisioned = false;

    public function __construct(
        private readonly CreateWorkspace $createWorkspace,
        private readonly SetupWorkspace $setupWorkspace,
        private readonly App $app,
        private readonly Node $node,
        private readonly string $name,
        private readonly string $base,
        private readonly ?string $phpVersion,
    ) {}

    public function title(): string
    {
        return 'Creating Workspace';
    }

    /**
     * @return list<array{key: string, label: string, doneLabel: string, run: callable(): string}>
     */
    public function steps(): array
    {
        $sourceLabels = $this->createWorkspace->sourceProgressLabels($this->app, $this->node);

        return [
            [
                'key' => 'provision_workspace_source',
                'label' => $sourceLabels['label'],
                'doneLabel' => $sourceLabels['done_label'],
                'run' => function (): string {
                    try {
                        $this->createWorkspace->ensureNodeReachable($this->node);
                        $provisionResult = $this->createWorkspace->provisionWorkspaceSource(
                            $this->app,
                            $this->node,
                            $this->name,
                            $this->base,
                        );
                        $this->workspace = $this->createWorkspace->createIntent(
                            $this->app,
                            $this->phpVersion,
                            $provisionResult,
                        );
                    } catch (WorkspaceCreateFailed $exception) {
                        $this->failure = [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'meta' => $exception->meta,
                        ];

                        throw $exception;
                    }

                    $this->workspaceSourceProvisioned = true;

                    return $this->workspace->path;
                },
            ],
            [
                'key' => 'register_proxy_routes',
                'label' => 'Register proxy routes',
                'doneLabel' => 'Registered proxy routes',
                'run' => function (): string {
                    if (! $this->workspace instanceof Workspace || ! $this->workspaceSourceProvisioned) {
                        return 'skip:Workspace source was not provisioned.';
                    }

                    $this->setupWorkspace->prepareWorkspaceState($this->workspace);
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
                    if (! $this->workspace instanceof Workspace || ! $this->workspaceSourceProvisioned) {
                        return 'skip:Workspace source was not provisioned.';
                    }

                    $warning = $this->setupWorkspace->enactRuntimeContainer($this->workspace, $this->node);

                    if ($warning !== null) {
                        $this->warnings[] = $warning;

                        return 'skip:'.$warning['message'];
                    }

                    return 'ready';
                },
            ],
            [
                'key' => 'run_workspace_setup_steps',
                'label' => 'Run workspace setup steps',
                'doneLabel' => 'Ran workspace setup steps',
                'run' => function (): string {
                    if (! $this->workspace instanceof Workspace || ! $this->workspaceSourceProvisioned) {
                        return 'skip:Workspace source was not provisioned.';
                    }

                    $setupResult = $this->setupWorkspace->runSetupSteps($this->workspace, $this->app, $this->node);

                    if ($setupResult['status'] === 'failed') {
                        $this->failure = [
                            'code' => 'workspace.enactment_failed',
                            'message' => "Workspace enactment on node '{$this->node->name}' stopped before Orbit could classify remaining drift.",
                            'meta' => [
                                'step' => 'setup_pipeline',
                                'node' => $this->node->name,
                                'reason' => $setupResult['message'],
                            ],
                        ];

                        throw new RuntimeException($this->failure['message']);
                    }

                    return $setupResult['message'];
                },
            ],
            [
                'key' => 'render_inherited_runtime_units',
                'label' => 'Render inherited runtime units',
                'doneLabel' => 'Rendered inherited runtime units',
                'run' => function (): string {
                    if (! $this->workspace instanceof Workspace || ! $this->workspaceSourceProvisioned) {
                        return 'skip:Workspace source was not provisioned.';
                    }

                    $processResult = $this->setupWorkspace->startProcesses($this->app, $this->workspace, $this->node);

                    if (! $processResult['success']) {
                        $this->failure = [
                            'code' => 'workspace.enactment_failed',
                            'message' => "Workspace enactment on node '{$this->node->name}' stopped before Orbit could classify remaining drift.",
                            'meta' => [
                                'step' => 'processes',
                                'node' => $this->node->name,
                                'reason' => $processResult['message'],
                            ],
                        ];

                        throw new RuntimeException($this->failure['message']);
                    }

                    if ($processResult['count'] === 0) {
                        return 'No inherited runtime units';
                    }

                    return implode(', ', $processResult['names']);
                },
            ],
            [
                'key' => 'check_workspace_readiness',
                'label' => 'Check workspace readiness',
                'doneLabel' => 'Checked workspace readiness',
                'run' => function (): string {
                    if (! $this->workspace instanceof Workspace || ! $this->workspaceSourceProvisioned) {
                        return 'skip:Workspace source was not provisioned.';
                    }

                    $this->httpProbe = $this->setupWorkspace->probeReadiness($this->workspace);

                    if (! $this->httpProbe['reachable']) {
                        $warning = [
                            'code' => 'workspace.http_probe_unhealthy',
                            'family' => 'workspace',
                            'message' => "Workspace did not become reachable: {$this->httpProbe['status']}",
                            'next_command' => 'doctor --family=workspace --restore',
                        ];
                        $this->warnings[] = $warning;
                        $this->setupWorkspace->markActive($this->workspace);

                        return 'skip:'.$warning['message'];
                    }

                    $this->setupWorkspace->markActive($this->workspace);

                    return (string) $this->httpProbe['status'];
                },
            ],
        ];
    }

    public function runForReporter(ProgressReporter $reporter): int
    {
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
                        'step' => $step['key'],
                        'node' => $this->node->name,
                    ],
                ];
                $reporter->stepFail($step['key'], $e->getMessage());

                return 1;
            }

            if (str_starts_with($message, 'skip:')) {
                $reporter->stepSkip($step['key'], substr($message, 5));

                continue;
            }

            $reporter->stepDone($step['key'], $message === '' ? null : $message);
        }

        return 0;
    }

    public function doneFooter(): string
    {
        return "Workspace '{$this->name}' created";
    }

    public function failFooter(): string
    {
        return "Failed to create workspace '{$this->name}'.";
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
     *     result: array{action: 'created'},
     *     workspace: array<string, mixed>,
     *     meta: array<string, mixed>,
     * }
     */
    public function result(): array
    {
        if (! $this->workspace instanceof Workspace) {
            return [
                'result' => ['action' => 'created'],
                'workspace' => [],
                'meta' => [
                    'node' => $this->node->name,
                    'base' => $this->base,
                    'http_probe' => $this->httpProbe,
                    'warnings' => $this->warnings,
                ],
            ];
        }

        return $this->createWorkspace->result($this->workspace, $this->app, $this->node, $this->base, $this->httpProbe, $this->warnings);
    }
}
