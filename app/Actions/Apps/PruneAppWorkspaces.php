<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Actions\Workspaces\RemoveWorkspace;
use App\Contracts\AgentIdeMessageAdapter;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppAgentIdeDefaults;
use RuntimeException;

final readonly class PruneAppWorkspaces
{
    public function __construct(
        private RemoveWorkspace $removeWorkspace,
        private AppAgentIdeDefaults $agentIdeDefaults,
        private AgentIdeMessageAdapter $adapter,
    ) {}

    /**
     * @return array{
     *     app: string,
     *     stale_workspaces: list<array{name: string, removed: bool}>,
     *     warnings: list<array<string, string>>,
     *     dry_run: bool,
     * }
     */
    public function handle(App $app, bool $dryRun = false, ?string $adapterName = null): array
    {
        $app->loadMissing('node');

        $effectiveAdapter = $adapterName ?? $this->agentIdeDefaults->payloadFor($app)['effective_adapter'];

        if ($effectiveAdapter === null) {
            throw new RuntimeException('No agent IDE adapter configured for this app.');
        }

        $nodeName = $app->node instanceof Node ? $app->node->name : '';

        $adapterWorkspaces = $this->adapter->workspaces(
            ['app' => $app->name, 'node' => $nodeName],
            $effectiveAdapter,
        );

        $trackedWorkspaces = Workspace::query()
            ->where('app_id', $app->id)
            ->pluck('name')
            ->all();

        $staleWorkspaces = array_values(array_diff($trackedWorkspaces, $adapterWorkspaces));

        if ($dryRun) {
            return [
                'app' => $app->name,
                'stale_workspaces' => array_map(fn (string $name): array => [
                    'name' => $name,
                    'removed' => false,
                ], $staleWorkspaces),
                'warnings' => [],
                'dry_run' => true,
            ];
        }

        $results = [];
        $warnings = [];

        foreach ($staleWorkspaces as $workspaceName) {
            $workspace = Workspace::query()
                ->where('app_id', $app->id)
                ->where('name', $workspaceName)
                ->first();

            if (! $workspace instanceof Workspace) {
                continue;
            }

            try {
                $result = $this->removeWorkspace->handle($workspace, keepFiles: false);

                if (! empty($result['warnings'])) {
                    $warnings = array_merge($warnings, $result['warnings']);
                }

                $results[] = [
                    'name' => $workspaceName,
                    'removed' => true,
                ];
            } catch (RuntimeException $e) {
                $warnings[] = [
                    'code' => 'workspace.remove_failed',
                    'family' => 'workspace',
                    'message' => "Failed to remove workspace '{$workspaceName}': {$e->getMessage()}",
                    'next_command' => "workspace:remove {$workspaceName} --app={$app->name} --force",
                ];

                $results[] = [
                    'name' => $workspaceName,
                    'removed' => false,
                ];
            }
        }

        return [
            'app' => $app->name,
            'stale_workspaces' => $results,
            'warnings' => $warnings,
            'dry_run' => false,
        ];
    }
}
