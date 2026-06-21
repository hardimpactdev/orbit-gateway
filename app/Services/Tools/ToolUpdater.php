<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\RemoteShell\RemoteSecretFile;

final readonly class ToolUpdater
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private RemoteShell $remoteShell,
        private NodeRoleAssignments $nodeRoleAssignments,
        private RemoteSecretFile $remoteSecretFile,
        private GitHubTokenResolver $githubTokenResolver,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function update(
        string $tool,
        ?string $node = null,
        ?string $app = null,
        ?string $expectedVersion = null,
        ?string $instance = null,
    ): array|ToolRegistryFailure {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, 'update');
        }

        if (! $this->catalog->hasCapability($tool, 'update')) {
            return ToolRegistryFailure::unsupportedAction($tool, 'update');
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app, instance: $instance);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        $model->loadMissing('node');

        if ($model->node === null) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', 'update', 1, 'Target node is missing.');
        }

        $config = is_array($model->config) ? $model->config : [];
        $script = $this->catalog->updateScript($tool, $config);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'update');
        }

        if ($expectedVersion !== null) {
            $model->expected_version = $expectedVersion;
            $model->save();
        }

        $result = $this->runToolScriptWithGitHubAuth(
            node: $model->node,
            tool: $tool,
            config: $config,
            scriptFactory: fn (array $config): string => (string) $this->catalog->updateScript($tool, $config),
        );

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $model->node->name,
                'update',
                $result->exitCode,
                trim($result->stderr),
            );
        }

        return [
            'name' => $tool,
            'node' => $model->node->name,
            'version' => $model->expected_version,
        ];
    }

    /**
     * @return array{updated: list<array<string, mixed>>, skipped: list<array<string, mixed>>, failed: list<array<string, mixed>>}
     */
    public function updateAll(?string $node = null, ?string $app = null): array
    {
        $query = NodeTool::query()->with('node');

        if ($node !== null) {
            $nodeModel = Node::query()
                ->where('name', $node)
                ->whereIn('id', $this->nodeRoleAssignments->activeToolHostNodeIds())
                ->where('status', NodeStatus::Active->value)
                ->first();

            if ($nodeModel instanceof Node) {
                $query->where('node_id', $nodeModel->id);
            }
        }

        if ($app !== null) {
            $appModel = App::query()
                ->with('node')
                ->where(function ($query) use ($app): void {
                    $query->where('name', $app)
                        ->orWhere('domain', $app);
                })
                ->first();

            if ($appModel instanceof App && $appModel->node instanceof Node) {
                $query->where('node_id', $appModel->node->id);
            }
        }

        $updated = [];
        $skipped = [];
        $failed = [];

        foreach ($query->get() as $nt) {
            $tool = $nt->name;

            $nodeName = $nt->node instanceof Node ? $nt->node->name : '';

            if (! $this->catalog->hasCapability($tool, 'update')) {
                $skipped[] = [
                    'tool' => $tool,
                    'node' => $nodeName,
                    'reason' => 'update_capability_missing',
                ];

                continue;
            }

            $latestVersion = $this->catalog->latestSupportedVersion($tool);

            if ($latestVersion === null) {
                $skipped[] = [
                    'tool' => $tool,
                    'node' => $nodeName,
                    'reason' => 'null_latest_version',
                ];

                continue;
            }

            $config = is_array($nt->config) ? $nt->config : [];
            $script = $this->catalog->updateScript($tool, $config);

            if ($script === null) {
                $skipped[] = [
                    'tool' => $tool,
                    'node' => $nodeName,
                    'reason' => 'null_update_script',
                ];

                continue;
            }

            $nt->expected_version = $latestVersion;
            $nt->save();

            if ($nt->node === null) {
                $failed[] = [
                    'tool' => $tool,
                    'node' => '',
                    'error' => 'Target node is missing.',
                ];

                continue;
            }

            $result = $this->runToolScriptWithGitHubAuth(
                node: $nt->node,
                tool: $tool,
                config: $config,
                scriptFactory: fn (array $config): string => (string) $this->catalog->updateScript($tool, $config),
            );

            if (! $result->successful()) {
                $failed[] = [
                    'tool' => $tool,
                    'node' => $nt->node->name,
                    'error' => trim($result->stderr) ?: 'update script failed',
                ];

                continue;
            }

            $updated[] = [
                'tool' => $tool,
                'node' => $nt->node->name,
            ];
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  callable(array<string, mixed>): string  $scriptFactory
     */
    private function runToolScriptWithGitHubAuth(Node $node, string $tool, array $config, callable $scriptFactory): RemoteShellResult
    {
        $token = $this->githubTokenForTool($tool);

        if ($token === null) {
            return $this->remoteShell->run($node, $scriptFactory($config), ['throw' => false]);
        }

        return $this->remoteSecretFile->stage(
            $node,
            $token,
            fn (string $path): RemoteShellResult => $this->remoteShell->run(
                $node,
                $scriptFactory([...$config, 'github_token_file' => $path]),
                ['throw' => false],
            ),
        );
    }

    private function githubTokenForTool(string $tool): ?string
    {
        if ($tool !== 'laravel-installer') {
            return null;
        }

        return $this->githubTokenResolver->token();
    }
}
