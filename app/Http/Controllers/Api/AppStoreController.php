<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Apps\CreateAppSourceOnNode;
use App\Actions\Apps\EnactAppRuntime;
use App\Contracts\Loggable;
use App\Data\Apps\AppInstanceRuntimeRequirementsData;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\ActivityLogType;
use App\Enums\Apps\AppInstanceDriver;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Php\PhpRuntimeCatalog;
use App\Support\GitRepositoryReference;
use App\Support\Streaming\ProgressEventStreamEmitter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

#[RequiresPermission('app:new', servingNode: ServingNode::Target)]
final class AppStoreController implements Loggable
{
    private ?App $activitySubject = null;

    public function __construct(
        private readonly NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function __invoke(
        Request $request,
        CreateAppSourceOnNode $createAppSourceOnNode,
        EnactAppRuntime $enactAppRuntime,
        ProgressEventStreamResponseFactory $streams,
        OperationRunRecorder $operationRuns,
    ): JsonResponse|StreamedResponse {
        $input = $this->validatedInput($request);

        if ($input instanceof JsonResponse) {
            return $input;
        }

        $requiredRole = $input['domain'] !== null ? 'app-prod' : 'app-dev';
        $node = $this->resolveTargetNode($input['node'], $requiredRole);

        if ($node instanceof JsonResponse) {
            return $node;
        }

        $existingApp = App::query()->with('node')->where('name', $input['name'])->first();

        if ($existingApp instanceof App) {
            return $this->error('app.collision', "App name '{$input['name']}' is already registered in the gateway app registry on node '{$existingApp->node?->name}'.", [
                'name' => $input['name'],
                'node' => $existingApp->node?->name,
            ], 409);
        }

        $routeDomain = $this->proxyRouteDomain($input, $node);
        $existingRoute = ProxyRoute::query()
            ->where('domain', $routeDomain)
            ->first();

        if ($existingRoute instanceof ProxyRoute) {
            return $this->error('proxy.domain_conflict', "Proxy route domain '{$routeDomain}' is already registered.", [
                'domain' => $routeDomain,
                'owner_type' => $existingRoute->owner_type,
                'kind' => $existingRoute->kind,
            ], 409);
        }

        if ($this->wantsEventStream($request)) {
            return $this->stream($request, $streams, $operationRuns, $createAppSourceOnNode, $enactAppRuntime, $input, $node);
        }

        $source = $createAppSourceOnNode->handle($node, $input['name'], $input['repository'], $input['domain']);

        if (! $source['result']->successful()) {
            return $this->error('app.source_creation_failed', "Source creation for app '{$input['name']}' failed on node '{$node->name}'.", [
                'reason' => trim($source['result']->output()) ?: 'source creation failed',
                ...($input['repository'] !== null ? ['transport' => GitRepositoryReference::transport($input['repository'])] : []),
            ], 500);
        }

        $app = App::query()->create([
            'name' => $input['name'],
            'node_id' => $node->id,
            'environment' => $input['domain'] !== null ? 'production' : 'development',
            'domain' => $input['domain'],
            'path' => $source['path'],
            'document_root' => $input['root'],
            'repository' => $input['repository'],
            'php_version' => $input['php_version'],
            'adopted' => false,
        ]);

        $app->setRelation('node', $node);
        $this->ensureDefaultInstance($app, $node);
        $this->activitySubject = $app;
        $warnings = $enactAppRuntime->handle($app);

        return response()->json([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'app' => $this->appPayload($app),
                ],
                'meta' => ['warnings' => $warnings],
            ],
        ]);
    }

    /**
     * @param  array{name: string, node: string, repository: ?string, root: string, php_version: string, domain: ?string}  $input
     */
    private function stream(
        Request $request,
        ProgressEventStreamResponseFactory $streams,
        OperationRunRecorder $operationRuns,
        CreateAppSourceOnNode $createAppSourceOnNode,
        EnactAppRuntime $enactAppRuntime,
        array $input,
        Node $node,
    ): StreamedResponse {
        /** @var mixed $caller */
        $caller = $request->user();
        $callerNodeId = $caller instanceof Node ? $caller->id : null;
        $operationRun = $operationRuns->queued(
            operationId: (string) Str::uuid(),
            lane: 'gateway',
            internalCommand: 'app:new',
            operationType: 'app:new',
            callerNodeId: $callerNodeId,
            targetNodeId: $node->id,
        );

        return $streams->make(function (ProgressEventStreamEmitter $events) use ($operationRuns, $operationRun, $createAppSourceOnNode, $enactAppRuntime, $input, $node): void {
            $events->tree('Creating App', [
                ['key' => 'operation', 'label' => 'Record operation state'],
                ['key' => 'source', 'label' => 'Create app source'],
                ['key' => 'registry', 'label' => 'Write app registry'],
                ['key' => 'runtime', 'label' => 'Apply app runtime'],
            ]);

            $operationRun = $operationRuns->running($operationRun->id);
            $events->stepEvent('operation', 'done', "Operation {$operationRun->id} running");
            $events->stepEvent('source', 'running', "Creating source for {$input['name']}");

            try {
                $source = $createAppSourceOnNode->handle($node, $input['name'], $input['repository'], $input['domain']);

                if (! $source['result']->successful()) {
                    $error = [
                        'code' => 'app.source_creation_failed',
                        'message' => "Source creation for app '{$input['name']}' failed on node '{$node->name}'.",
                        'meta' => [
                            'reason' => trim($source['result']->output()) ?: 'source creation failed',
                            ...($input['repository'] !== null ? ['transport' => GitRepositoryReference::transport($input['repository'])] : []),
                        ],
                    ];

                    $operationRun = $operationRuns->failed($operationRun->id, 1, $error);
                    $events->stepEvent('source', 'fail', $error['message']);
                    $events->error($error['message'], 1, [
                        ...$error,
                        'operation_run' => $this->operationRunPayload($operationRun),
                    ]);

                    return;
                }

                $events->stepEvent('source', 'done', 'App source ready');
                $events->stepEvent('registry', 'running', "Registering {$input['name']}");

                $app = App::query()->create([
                    'name' => $input['name'],
                    'node_id' => $node->id,
                    'environment' => $input['domain'] !== null ? 'production' : 'development',
                    'domain' => $input['domain'],
                    'path' => $source['path'],
                    'document_root' => $input['root'],
                    'repository' => $input['repository'],
                    'php_version' => $input['php_version'],
                    'adopted' => false,
                ]);

                $app->setRelation('node', $node);
                $this->ensureDefaultInstance($app, $node);
                $this->activitySubject = $app;
                $events->stepEvent('registry', 'done', 'App registered');
                $events->stepEvent('runtime', 'running', "Applying runtime for {$app->name}");

                $warnings = $enactAppRuntime->handle($app);
                $events->stepEvent('runtime', 'done', 'App runtime applied');

                $data = [
                    'footer' => "App '{$app->name}' created.",
                    'operation_run' => $this->operationRunPayload($operationRuns->succeeded($operationRun->id, 0, [
                        'result' => ['action' => 'created'],
                        'app' => $this->appPayload($app),
                        'warnings' => $warnings,
                    ])),
                    'result' => ['action' => 'created'],
                    'app' => $this->appPayload($app),
                    'warnings' => $warnings,
                ];

                $events->complete(0, $data);
            } catch (Throwable $exception) {
                $error = [
                    'code' => 'app.creation_failed',
                    'message' => $exception->getMessage() !== '' ? $exception->getMessage() : 'App creation failed.',
                    'meta' => [
                        'app' => $input['name'],
                        'node' => $node->name,
                    ],
                ];
                $operationRun = $operationRuns->failed($operationRun->id, 1, $error);
                $events->stepEvent('runtime', 'fail', $error['message']);
                $events->error($error['message'], 1, [
                    ...$error,
                    'operation_run' => $this->operationRunPayload($operationRun),
                ]);
            }
        });
    }

    private function wantsEventStream(Request $request): bool
    {
        return in_array('text/event-stream', $request->getAcceptableContentTypes(), true);
    }

    private function ensureDefaultInstance(App $app, Node $node): void
    {
        $app->instances()->firstOrCreate(
            ['name' => $app->environment],
            [
                'driver' => AppInstanceDriver::Orbit,
                'driver_config' => new OrbitAppInstanceDriverConfigData(
                    node_id: $node->id,
                    node: $node->name,
                    path: $app->path,
                    document_root: $app->document_root,
                    domain: $app->domain,
                ),
                'runtime_requirements' => new AppInstanceRuntimeRequirementsData,
            ],
        );
    }

    /**
     * @return array{name: string, node: string, repository: ?string, root: string, php_version: string, domain: ?string}|JsonResponse
     */
    private function validatedInput(Request $request): array|JsonResponse
    {
        $name = $this->stringInput($request, 'name');
        $node = $this->stringInput($request, 'node');
        $repository = GitRepositoryReference::canonicalize($this->stringInput($request, 'repository'));
        $root = $this->stringInput($request, 'root') ?? 'public';
        $phpVersion = $this->stringInput($request, 'php_version') ?? PhpRuntimeCatalog::DEFAULT;
        $domain = $this->stringInput($request, 'domain');

        if ($name === null) {
            return $this->validationFailed('name', 'App name is required.');
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) || mb_strlen($name) > 40) {
            return $this->validationFailed('name', 'App name must be a slug of 40 characters or fewer.');
        }

        if ($node === null) {
            return $this->validationFailed('node', 'The node field is required.');
        }

        if ($repository === false) {
            return $this->validationFailed('repository', 'Repository must be a full Git URL or GitHub owner/repo shorthand.');
        }

        if ($root === '' || preg_match('/[\x00-\x1F;`$|&<>"\'\\\\]/', $root)) {
            return $this->validationFailed('root', 'Document root is invalid.');
        }

        if (! in_array($phpVersion, PhpRuntimeCatalog::SUPPORTED, true)) {
            return $this->validationFailed('php_version', 'Unsupported PHP version.');
        }

        if ($domain !== null && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return $this->validationFailed('domain', 'Production domain is invalid.');
        }

        return [
            'name' => $name,
            'node' => $node,
            'repository' => $repository,
            'root' => $root,
            'php_version' => $phpVersion,
            'domain' => $domain,
        ];
    }

    private function resolveTargetNode(string $nodeName, string $requiredRole): Node|JsonResponse
    {
        $node = Node::query()->where('name', $nodeName)->first();

        if (! $node instanceof Node) {
            return $this->validationFailed('node', "Node '{$nodeName}' was not found.");
        }

        if (! $node->isActive() || ! $this->nodeRoleAssignments->nodeHasActiveRole($node, $requiredRole)) {
            return $this->error('app.ineligible_node', "Node '{$node->name}' is not an active app node.", [
                'node' => $node->name,
                'required_role' => $requiredRole,
                'status' => $node->status->value,
            ], 400);
        }

        return $node;
    }

    /**
     * @param  array{name: string, node: string, repository: ?string, root: string, php_version: string, domain: ?string}  $input
     */
    private function proxyRouteDomain(array $input, Node $node): string
    {
        if ($input['domain'] !== null) {
            return $input['domain'];
        }

        $tld = is_string($node->tld) ? trim($node->tld, '.') : '';

        if ($tld === '') {
            return $input['name'];
        }

        return "{$input['name']}.{$tld}";
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'runtime_kind' => $app->runtime_kind->value,
            'php_version' => $app->php_version,
            'worker_enabled' => $app->worker_enabled,
            'worker_config' => is_array($app->worker_config) ? $app->worker_config : null,
            'adopted' => $app->adopted,
        ];
    }

    private function validationFailed(string $field, string $message): JsonResponse
    {
        return $this->error('validation_failed', $message, ['field' => $field], 400);
    }

    /**
     * @return array<string, mixed>
     */
    private function operationRunPayload(OperationRun $operationRun): array
    {
        return [
            'id' => $operationRun->id,
            'operation_id' => $operationRun->operation_id,
            'type' => $operationRun->operation_type,
            'status' => $operationRun->status->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:POST /apps';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
