<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Contracts\RemoteShell;
use App\Contracts\WorkspaceSourceDrivers;
use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Enums\WorkspaceLifecycleStatus;
use App\Exceptions\WorkspaceCreateFailed;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Workspaces\WorkspaceRoleGuard;
use RuntimeException;

final readonly class CreateWorkspace
{
    public const array SUPPORTED_PHP_VERSIONS = PhpRuntimeCatalog::SUPPORTED;

    public function __construct(
        private RemoteShell $remoteShell,
        private SetupWorkspace $setupWorkspace,
        private WorkspaceSourceDrivers $sourceDrivers,
        private WorkspaceRoleGuard $roleGuard,
    ) {}

    /**
     * @return array{
     *     result: array{action: 'created'},
     *     workspace: array<string, mixed>,
     *     meta: array<string, mixed>,
     * }
     */
    public function handle(App $app, string $name, string $base = 'main', ?string $phpVersion = null): array
    {
        $node = $this->resolveAppNode($app);
        $this->ensureSupportedPhpVersion($phpVersion);
        $this->ensureNodeReachable($node);

        $provisionResult = $this->provisionWorkspaceSource($app, $node, $name, $base);
        $workspace = $this->createIntent($app, $phpVersion, $provisionResult);

        $warnings = [];
        $httpProbe = [
            'reachable' => false,
            'status' => 'not_run',
        ];

        try {
            $setup = $this->setupWorkspace->handle($app, $workspace, $node);
            $warnings = array_merge($warnings, $setup['warnings']);
            $httpProbe = $setup['http_probe'];
        } catch (RuntimeException $exception) {
            throw new WorkspaceCreateFailed(
                'workspace.enactment_failed',
                "Workspace enactment on node '{$node->name}' stopped before Orbit could classify remaining drift.",
                [
                    'step' => 'setup_pipeline',
                    'node' => $node->name,
                    'reason' => $exception->getMessage(),
                ],
            );
        }

        return $this->result($workspace, $app, $node, $base, $httpProbe, $warnings);
    }

    public function resolveAppNode(App $app): Node
    {
        $app->loadMissing('node');

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new WorkspaceCreateFailed(
                'workspace.parent_app_invalid',
                "App '{$app->name}' does not have an owning app node.",
                ['field' => 'app', 'app' => $app->name],
            );
        }

        try {
            $this->roleGuard->ensureAppSupportsWorkspaces($app);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            throw new WorkspaceCreateFailed(
                $exception->errorCode(),
                $exception->getMessage(),
                $exception->meta,
            );
        }

        return $node;
    }

    public function ensureSupportedPhpVersion(?string $phpVersion): void
    {
        if ($phpVersion === null || in_array($phpVersion, self::SUPPORTED_PHP_VERSIONS, true)) {
            return;
        }

        throw new WorkspaceCreateFailed(
            'validation_failed',
            'Unsupported PHP version.',
            ['field' => 'php_version', 'reason' => 'unsupported_php_version'],
        );
    }

    public function ensureNodeReachable(Node $node): void
    {
        $preflight = $this->remoteShell->run($node, 'true', ['timeout' => 30]);

        if ($preflight->successful()) {
            return;
        }

        throw new WorkspaceCreateFailed(
            'workspace.ssh_failure',
            "Gateway could not reach app node '{$node->name}' before creating workspace intent.",
            [
                'node' => $node->name,
                'reason' => trim($preflight->output()) ?: 'ssh preflight failed',
            ],
        );
    }

    public function createIntent(App $app, ?string $phpVersion, WorkspaceProvisionResult $provisionResult): Workspace
    {
        $workspace = Workspace::create([
            'app_id' => $app->id,
            'name' => $provisionResult->name,
            'path' => $provisionResult->path,
            'php_version' => $phpVersion,
            'agent_ide' => $provisionResult->agentIde,
            'agent_ide_workspace_id' => $provisionResult->agentIdeWorkspaceId,
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        $workspace->setRelation('app', $app);

        return $workspace;
    }

    public function effectiveAgentIde(App $app): ?string
    {
        return $this->sourceDrivers->effectiveAdapter($app);
    }

    public function provisionWorkspaceSource(App $app, Node $node, string $name, string $base): WorkspaceProvisionResult
    {
        return $this->sourceDrivers->resolve($app)->create($app, $node, $name, $base);
    }

    /**
     * @return array{label: string, done_label: string}
     */
    public function sourceProgressLabels(App $app, Node $node): array
    {
        return $this->sourceDrivers->progressLabels($app, $node);
    }

    /**
     * @param  array{reachable: bool, status: string}  $httpProbe
     * @param  list<array<string, string>>  $warnings
     * @return array{
     *     result: array{action: 'created'},
     *     workspace: array<string, mixed>,
     *     meta: array<string, mixed>,
     * }
     */
    public function result(Workspace $workspace, App $app, Node $node, string $base, array $httpProbe, array $warnings): array
    {
        $workspace->refresh();
        $workspace->setRelation('app', $app);

        return [
            'result' => ['action' => 'created'],
            'workspace' => $this->workspacePayload($workspace, $app, $node),
            'meta' => [
                'node' => $node->name,
                'base' => $base,
                'http_probe' => $httpProbe,
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workspacePayload(Workspace $workspace, App $app, Node $node): array
    {
        return [
            'name' => $workspace->name,
            'app' => $app->name,
            'node' => $node->name,
            'path' => $workspace->path,
            'url' => $workspace->url(),
            'php_version' => $workspace->effectivePhpVersion(),
            'php_inherited' => $workspace->php_version === null,
            'agent_ide' => [
                'adapter' => $workspace->agent_ide,
                'workspace_id' => $workspace->agent_ide_workspace_id,
            ],
            'adopted' => false,
            'lifecycle_status' => $workspace->lifecycle_status->value,
        ];
    }
}
