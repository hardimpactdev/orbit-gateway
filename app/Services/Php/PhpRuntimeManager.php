<?php

declare(strict_types=1);

namespace App\Services\Php;

use App\Data\Php\PhpRuntimeFailure;
use App\Data\Php\PhpRuntimeOperation;
use App\Enums\Nodes\NodeStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;

final readonly class PhpRuntimeManager
{
    private const array SUPPORTED_CLI_PHP_VERSIONS = ['8.5'];

    public function __construct(
        private PhpRuntimeCatalog $catalog,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function view(?string $app = null, ?string $workspace = null, ?string $node = null, bool $live = false): PhpRuntimeOperation
    {
        $target = $this->resolveTarget(app: $app, workspace: $workspace, node: $node);

        if ($target instanceof PhpRuntimeFailure) {
            return new PhpRuntimeOperation(failure: $target);
        }

        $payload = $this->runtimeView(
            node: $target['node'],
            app: $target['app'],
            workspace: $target['workspace'],
        );

        return new PhpRuntimeOperation(
            payload: $payload,
            meta: $live ? ['live' => true] : [],
        );
    }

    public function use(?string $version, ?string $app = null, ?string $workspace = null, ?string $node = null, bool $inherit = false, bool $cli = false): PhpRuntimeOperation
    {
        $validation = $this->validateUseInputs($version, $app, $workspace, $inherit, $cli);

        if ($validation instanceof PhpRuntimeFailure) {
            return new PhpRuntimeOperation(failure: $validation);
        }

        $target = $this->resolveUseTarget(app: $app, workspace: $workspace, node: $node, inherit: $inherit, cli: $cli);

        if ($target instanceof PhpRuntimeFailure) {
            return new PhpRuntimeOperation(failure: $target);
        }

        $requestedVersion = is_string($version) ? trim($version) : null;

        if ($requestedVersion !== null && $target['scope'] === 'node_cli') {
            if (! in_array($requestedVersion, self::SUPPORTED_CLI_PHP_VERSIONS, true)) {
                return new PhpRuntimeOperation(failure: new PhpRuntimeFailure(
                    code: 'validation_failed',
                    message: "Unsupported CLI PHP version '{$requestedVersion}'.",
                    meta: [
                        'field' => 'version',
                        'reason' => 'unsupported_cli_version',
                        'supported' => self::SUPPORTED_CLI_PHP_VERSIONS,
                    ],
                ));
            }

            $availabilityFailure = $this->versionAvailabilityFailure($target['node'], $requestedVersion);

            if ($availabilityFailure instanceof PhpRuntimeFailure) {
                return new PhpRuntimeOperation(failure: $availabilityFailure);
            }
        }

        return match ($target['scope']) {
            'node_cli' => $this->useNodeCli($target['node'], (string) $requestedVersion),
            'workspace' => $this->useWorkspace($target['workspace'], $inherit, $requestedVersion),
            default => $this->useApp($target['app'], (string) $requestedVersion),
        };
    }

    /**
     * @return array{node: Node, app: App|null, workspace: Workspace|null}|PhpRuntimeFailure
     */
    private function resolveTarget(?string $app, ?string $workspace, ?string $node): array|PhpRuntimeFailure
    {
        $appModel = $this->resolveApp($app);

        if ($app !== null && ! $appModel instanceof App) {
            return $this->validationFailure('app', $app, "App '{$app}' not found or not visible.");
        }

        $workspaceModel = $this->resolveWorkspace($workspace, $appModel);

        if ($workspace !== null && ! $workspaceModel instanceof Workspace) {
            return $this->validationFailure('workspace', $workspace, "Workspace '{$workspace}' not found or not visible.");
        }

        if (! $appModel instanceof App && $workspaceModel instanceof Workspace) {
            $workspaceModel->loadMissing('app.node');
            $appModel = $workspaceModel->app;
        }

        $nodeModel = $this->resolveNode($node);

        if ($node !== null && ! $nodeModel instanceof Node) {
            return $this->validationFailure('node', $node, "Node '{$node}' not found or not visible.");
        }

        if ($appModel instanceof App) {
            $appModel->loadMissing('node');

            if ($nodeModel instanceof Node && $appModel->node instanceof Node && $nodeModel->id !== $appModel->node->id) {
                return $this->nodeTargetMismatch($nodeModel, $appModel);
            }

            $nodeModel ??= $appModel->node;
        }

        if ($nodeModel === null) {
            $nodeModel = $this->defaultNode();
        }

        if (! $nodeModel instanceof Node) {
            return new PhpRuntimeFailure(
                code: 'validation_failed',
                message: 'A node, app, or workspace target is required.',
                meta: [
                    'field' => 'node',
                    'reason' => 'missing_target',
                ],
            );
        }

        return [
            'node' => $nodeModel,
            'app' => $appModel,
            'workspace' => $workspaceModel,
        ];
    }

    /**
     * @return array{scope: string, node: Node, app: App|null, workspace: Workspace|null}|PhpRuntimeFailure
     */
    private function resolveUseTarget(?string $app, ?string $workspace, ?string $node, bool $inherit, bool $cli): array|PhpRuntimeFailure
    {
        if ($cli) {
            $nodeModel = $this->resolveNode($node) ?? $this->defaultNode();

            if (! $nodeModel instanceof Node) {
                return new PhpRuntimeFailure('validation_failed', 'A node target is required for CLI PHP selection.', [
                    'field' => 'node',
                    'reason' => 'missing_target',
                ]);
            }

            return [
                'scope' => 'node_cli',
                'node' => $nodeModel,
                'app' => null,
                'workspace' => null,
            ];
        }

        $target = $this->resolveTarget(app: $app, workspace: $workspace, node: $node);

        if ($target instanceof PhpRuntimeFailure) {
            return $target;
        }

        if (($inherit || $workspace !== null) && ! $target['workspace'] instanceof Workspace) {
            return new PhpRuntimeFailure('validation_failed', 'A workspace target is required.', [
                'field' => 'workspace',
                'reason' => 'missing_target',
            ]);
        }

        if ($target['workspace'] instanceof Workspace) {
            return [
                'scope' => 'workspace',
                'node' => $target['node'],
                'app' => $target['app'],
                'workspace' => $target['workspace'],
            ];
        }

        if (! $target['app'] instanceof App) {
            return new PhpRuntimeFailure('validation_failed', 'An app target is required.', [
                'field' => 'app',
                'reason' => 'missing_target',
            ]);
        }

        return [
            'scope' => 'app',
            'node' => $target['node'],
            'app' => $target['app'],
            'workspace' => null,
        ];
    }

    private function validateUseInputs(?string $version, ?string $app, ?string $workspace, bool $inherit, bool $cli): ?PhpRuntimeFailure
    {
        $version = is_string($version) ? trim($version) : null;

        if ($version === '') {
            $version = null;
        }

        if ($inherit && $version !== null) {
            return new PhpRuntimeFailure('validation_failed', 'Cannot provide both a PHP version and --inherit.', [
                'fields' => ['version', 'inherit'],
                'reason' => 'mutually_exclusive_input',
            ]);
        }

        if ($cli && ($app !== null || $workspace !== null || $inherit)) {
            return new PhpRuntimeFailure('validation_failed', 'CLI PHP selection cannot be combined with app, workspace, or inheritance targets.', [
                'fields' => ['cli', 'app', 'workspace', 'inherit'],
                'reason' => 'mutually_exclusive_input',
            ]);
        }

        if (! $inherit && $version === null) {
            return new PhpRuntimeFailure('validation_failed', 'PHP version is required.', [
                'field' => 'version',
                'reason' => 'missing_required_input',
            ]);
        }

        return null;
    }

    private function useApp(?App $app, string $version): PhpRuntimeOperation
    {
        if (! $app instanceof App) {
            return new PhpRuntimeOperation(failure: new PhpRuntimeFailure('validation_failed', 'An app target is required.', [
                'field' => 'app',
                'reason' => 'missing_target',
            ]));
        }

        $app->loadMissing('node');

        if ($app->node instanceof Node) {
            $availabilityFailure = $this->versionAvailabilityFailure($app->node, $version);

            if ($availabilityFailure instanceof PhpRuntimeFailure) {
                return new PhpRuntimeOperation(failure: $availabilityFailure);
            }
        }

        $previous = $app->php_version;
        $changed = $previous !== $version;
        $app->forceFill(['php_version' => $version])->save();

        return $this->selectionOperation(
            node: $app->node,
            app: $app->refresh(),
            workspace: null,
            result: [
                'target' => 'app',
                'node' => $app->node?->name,
                'app' => $app->name,
                'workspace' => null,
                'previous' => $previous,
                'version' => $version,
                'image' => $this->catalog->imageFor($version),
                'inherits' => false,
                'changed' => $changed,
            ],
        );
    }

    private function useWorkspace(?Workspace $workspace, bool $inherit, ?string $version): PhpRuntimeOperation
    {
        if (! $workspace instanceof Workspace) {
            return new PhpRuntimeOperation(failure: new PhpRuntimeFailure('validation_failed', 'A workspace target is required.', [
                'field' => 'workspace',
                'reason' => 'missing_target',
            ]));
        }

        $workspace->loadMissing('app.node');
        $previous = $workspace->php_version ?? $workspace->app?->php_version;
        $previousRaw = $workspace->php_version;
        $nextRaw = $inherit ? null : $version;
        $nextEffective = $nextRaw ?? $workspace->app?->php_version;

        if ($workspace->app?->node instanceof Node && is_string($nextEffective)) {
            $availabilityFailure = $this->versionAvailabilityFailure($workspace->app->node, $nextEffective);

            if ($availabilityFailure instanceof PhpRuntimeFailure) {
                return new PhpRuntimeOperation(failure: $availabilityFailure);
            }
        }

        $workspace->forceFill(['php_version' => $nextRaw])->save();
        $workspace->refresh()->loadMissing('app.node');
        $effective = $workspace->effectivePhpVersion();

        return $this->selectionOperation(
            node: $workspace->app?->node,
            app: $workspace->app,
            workspace: $workspace,
            result: [
                'target' => 'workspace',
                'node' => $workspace->app?->node?->name,
                'app' => $workspace->app?->name,
                'workspace' => $workspace->name,
                'previous' => $previous,
                'version' => $effective,
                'image' => $this->imageForSupportedVersion($effective),
                'inherits' => $inherit,
                'changed' => $previousRaw !== $nextRaw,
            ],
        );
    }

    private function useNodeCli(Node $node, string $version): PhpRuntimeOperation
    {
        $tool = $this->phpTool($node);
        $config = is_array($tool?->config) ? $tool->config : [];
        $previous = $this->cliVersion($node);
        $config['cli_version'] = $version;

        NodeTool::query()->updateOrCreate(
            ['node_id' => $node->id, 'name' => 'php'],
            [
                'expected_state' => $tool->expected_state ?? 'installed',
                'expected_version' => $tool?->expected_version,
                'config' => $config,
            ],
        );

        return $this->selectionOperation(
            node: $node,
            app: null,
            workspace: null,
            result: [
                'target' => 'node_cli',
                'node' => $node->name,
                'app' => null,
                'workspace' => null,
                'previous' => $previous,
                'version' => $version,
                'image' => $this->catalog->imageFor($version),
                'inherits' => false,
                'changed' => $previous !== $version,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function selectionOperation(?Node $node, ?App $app, ?Workspace $workspace, array $result): PhpRuntimeOperation
    {
        if (! $node instanceof Node) {
            return new PhpRuntimeOperation(failure: new PhpRuntimeFailure('validation_failed', 'A node target is required.', [
                'field' => 'node',
                'reason' => 'missing_target',
            ]));
        }

        return new PhpRuntimeOperation(
            payload: [
                'php' => $this->runtimeView($node, $app, $workspace),
                'result' => $result,
            ],
            meta: ['warnings' => []],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeView(Node $node, ?App $app = null, ?Workspace $workspace = null): array
    {
        return [
            'node' => $node->name,
            'supported' => $this->catalog->supported(),
            'available_images' => $this->availableImageVersions($node),
            'cli' => $this->cliVersion($node),
            'app' => $app instanceof App ? [
                'name' => $app->name,
                'php_version' => $app->php_version,
            ] : null,
            'workspace' => $workspace instanceof Workspace ? [
                'name' => $workspace->name,
                'php_version' => $workspace->effectivePhpVersion(),
                'inherits' => ! is_string($workspace->php_version) || $workspace->php_version === '',
            ] : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function availableImageVersions(Node $node): array
    {
        $tool = $this->phpTool($node);
        $config = is_array($tool?->config) ? $tool->config : [];
        $images = $config['images'] ?? null;

        if (is_array($images)) {
            $versions = [];

            foreach ($images as $image) {
                if (! is_string($image) || ! $this->catalog->isApprovedImage($image)) {
                    continue;
                }

                $versions[] = $this->catalog->versionForImage($image);
            }

            return array_values(array_unique($versions));
        }

        return [];
    }

    private function versionAvailabilityFailure(Node $node, string $version): ?PhpRuntimeFailure
    {
        $version = trim($version);

        if (! $this->catalog->supports($version)) {
            return new PhpRuntimeFailure(
                code: 'validation_failed',
                message: "Unsupported PHP version '{$version}'.",
                meta: [
                    'field' => 'version',
                    'reason' => 'unsupported',
                    'supported' => $this->catalog->supported(),
                ],
            );
        }

        if (in_array($version, $this->availableImageVersions($node), true)) {
            return null;
        }

        return new PhpRuntimeFailure(
            code: 'validation_failed',
            message: "Approved FrankenPHP image for PHP {$version} is not available on node '{$node->name}'.",
            meta: [
                'field' => 'version',
                'reason' => 'not_installed',
                'node' => $node->name,
                'version' => $version,
                'image' => $this->catalog->imageFor($version),
                'rejected_images' => $this->rejectedImages($node),
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function rejectedImages(Node $node): array
    {
        $tool = $this->phpTool($node);
        $config = is_array($tool?->config) ? $tool->config : [];
        $images = $config['images'] ?? null;

        if (! is_array($images)) {
            return [];
        }

        return array_values(array_filter(
            $images,
            fn (mixed $image): bool => is_string($image) && ! $this->catalog->isApprovedImage($image),
        ));
    }

    private function imageForSupportedVersion(?string $version): ?string
    {
        if (! is_string($version) || trim($version) === '') {
            return null;
        }

        $version = trim($version);

        return $this->catalog->supports($version)
            ? $this->catalog->imageFor($version)
            : null;
    }

    private function cliVersion(Node $node): ?string
    {
        $tool = $this->phpTool($node);
        $cliVersion = $tool?->config['cli_version'] ?? null;

        return is_string($cliVersion) && $cliVersion !== '' ? $cliVersion : null;
    }

    private function phpTool(Node $node): ?NodeTool
    {
        return NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'php')
            ->first();
    }

    private function resolveApp(?string $selector): ?App
    {
        if ($selector === null) {
            return null;
        }

        return App::query()
            ->with('node')
            ->where(function (Builder $query) use ($selector): void {
                $query->where('name', $selector)
                    ->orWhere('domain', $selector);
            })
            ->first();
    }

    private function resolveWorkspace(?string $selector, ?App $app): ?Workspace
    {
        if ($selector === null) {
            return null;
        }

        $query = Workspace::query()
            ->with('app.node')
            ->where('name', $selector);

        if ($app instanceof App) {
            $query->where('app_id', $app->id);
        }

        $matches = $query->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function resolveNode(?string $selector): ?Node
    {
        if ($selector === null) {
            return null;
        }

        return Node::query()
            ->where('name', $selector)
            ->where('status', NodeStatus::Active->value)
            ->first();
    }

    private function defaultNode(): ?Node
    {
        $nodes = Node::query()
            ->whereIn('id', $this->nodeRoleAssignments->activeAppHostNodeIds())
            ->where('status', NodeStatus::Active->value)
            ->limit(2)
            ->get();

        return $nodes->count() === 1 ? $nodes->first() : null;
    }

    private function validationFailure(string $field, string $value, string $message): PhpRuntimeFailure
    {
        return new PhpRuntimeFailure('validation_failed', $message, [
            'field' => $field,
            'value' => $value,
        ]);
    }

    private function nodeTargetMismatch(Node $node, App $app): PhpRuntimeFailure
    {
        return new PhpRuntimeFailure(
            code: 'validation_failed',
            message: "Node '{$node->name}' does not own app '{$app->name}'.",
            meta: [
                'field' => 'node',
                'reason' => 'target_mismatch',
                'node' => $node->name,
                'app' => $app->name,
                'owning_node' => $app->node?->name,
            ],
        );
    }
}
