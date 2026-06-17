<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workspaces\SetupWorkspace;
use App\Actions\Workspaces\SetupWorkspaceProgress;
use App\Contracts\Loggable;
use App\Contracts\ProgressReporter;
use App\Enums\ActivityLogType;
use App\Exceptions\WorkspaceSetupResolutionFailed;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspaceSetupTargetResolver;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[RequiresPermission('workspace:setup', servingNode: ServingNode::WorkspaceOwning)]
final class WorkspaceSetupController implements Loggable
{
    private ?Workspace $activitySubject = null;

    public function __construct(
        private readonly SetupWorkspace $setupWorkspace,
        private readonly NodeAccessAuthorizer $authorizer,
    ) {}

    public function __invoke(
        Request $request,
        SetupWorkspaceProgress $setupProgress,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        if ($this->wantsEventStream($request)) {
            return $this->stream($request, $setupProgress, $streams);
        }

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $validator = validator($request->all(), [
            'name' => ['nullable', 'string'],
            'app' => ['nullable', 'string'],
            'path' => ['nullable', 'string', 'starts_with:/'],
            'caller_cwd' => ['nullable', 'string', 'starts_with:/'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $field = $errors->keys()[0] ?? 'unknown';

            return $this->error('validation_failed', $errors->first(), ['field' => $field], 422);
        }

        $validated = $validator->validated();

        $name = $validated['name'] ?? null;
        $appName = $validated['app'] ?? null;
        $path = $validated['path'] ?? null;
        $callerCwd = $validated['caller_cwd'] ?? null;

        try {
            [$workspace, $app, $node, $isAdoption] = app(WorkspaceSetupTargetResolver::class)->resolve($name, $appName, $path, $callerCwd, $caller);
        } catch (WorkspaceSetupResolutionFailed $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->meta, 422);
        } catch (\RuntimeException $e) {
            $field = $this->resolveErrorField($e->getMessage());
            $code = $this->resolveErrorCode($e->getMessage(), $field);

            return $this->error(
                $code,
                $e->getMessage(),
                ['field' => $field],
                422,
            );
        }

        $authorization = $this->authorizer->authorize($caller, $node, 'workspace:setup');

        if (! $authorization->allowed) {
            return $this->forbidden($node, $authorization, 'workspace:setup');
        }

        $this->activitySubject = $workspace;

        try {
            $result = $this->setupWorkspace->handle($app, $workspace, $node, $isAdoption);
        } catch (\RuntimeException $e) {
            return $this->error(
                'workspace.enactment_failed',
                $e->getMessage(),
                [
                    'phase' => 'artifacts',
                    'node' => $node->name,
                ],
                422,
            );
        }

        $data = [
            'app' => $result['app'],
            'workspace' => $result['workspace'],
            'node' => $result['node'],
            'url' => $result['url'],
            'action' => $result['action'],
            'setup_steps' => $result['setup_steps'],
            'processes' => $result['processes'],
            'http_probe' => $result['http_probe'],
        ];

        $meta = [];

        if ($result['warnings'] !== []) {
            $meta['warnings'] = $result['warnings'];
        }

        return response()->json([
            'success' => [
                'data' => $data,
                'meta' => $meta,
            ],
        ], 200);
    }

    private function stream(
        Request $request,
        SetupWorkspaceProgress $setupProgress,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $validator = validator($request->all(), [
            'name' => ['nullable', 'string'],
            'app' => ['nullable', 'string'],
            'path' => ['nullable', 'string', 'starts_with:/'],
            'caller_cwd' => ['nullable', 'string', 'starts_with:/'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $field = $errors->keys()[0] ?? 'unknown';

            return $this->error('validation_failed', $errors->first(), ['field' => $field], 422);
        }

        $validated = $validator->validated();

        $name = $validated['name'] ?? null;
        $appName = $validated['app'] ?? null;
        $path = $validated['path'] ?? null;
        $callerCwd = $validated['caller_cwd'] ?? null;

        try {
            [$workspace, $app, $node, $isAdoption] = app(WorkspaceSetupTargetResolver::class)->resolve($name, $appName, $path, $callerCwd, $caller);
        } catch (WorkspaceSetupResolutionFailed $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->meta, 422);
        } catch (\RuntimeException $e) {
            $field = $this->resolveErrorField($e->getMessage());
            $code = $this->resolveErrorCode($e->getMessage(), $field);

            return $this->error(
                $code,
                $e->getMessage(),
                ['field' => $field],
                422,
            );
        }

        $authorization = $this->authorizer->authorize($caller, $node, 'workspace:setup');

        if (! $authorization->allowed) {
            return $this->forbidden($node, $authorization, 'workspace:setup');
        }

        $this->activitySubject = $workspace;

        return $streams->make(function ($emitter) use ($setupProgress, $workspace, $app, $node, $isAdoption): void {
            $plan = $setupProgress->for($workspace, $app, $node, $isAdoption);
            $exitCode = $plan->runForReporter(app(ProgressReporter::class));

            if ($exitCode !== 0) {
                $failure = $plan->failure() ?? [
                    'code' => 'workspace.enactment_failed',
                    'message' => 'Workspace setup failed.',
                    'meta' => [
                        'phase' => 'artifacts',
                        'node' => $node->name,
                    ],
                ];

                $emitter->error($failure['message'], 1, [
                    'code' => $failure['code'],
                    'message' => $failure['message'],
                    'meta' => $failure['meta'],
                    'footer' => $plan->failFooter(),
                ]);

                return;
            }

            $emitter->complete(0, [
                'footer' => $plan->doneFooter(),
                'result' => $plan->result(),
            ]);
        });
    }

    private function wantsEventStream(Request $request): bool
    {
        return in_array('text/event-stream', $request->getAcceptableContentTypes(), true);
    }

    private function resolveErrorField(string $message): string
    {
        if (str_contains($message, 'App')) {
            return 'app';
        }

        if (str_starts_with($message, 'Path ')) {
            return 'path';
        }

        return 'workspace';
    }

    private function resolveErrorCode(string $message, string $field): string
    {
        if (str_contains($message, 'outside the parent app workspace policy')) {
            return 'workspace.path_outside_policy';
        }

        return $field === 'app' ? 'validation_failed' : 'workspace.not_found';
    }

    private function error(string $code, string $message, array $meta = [], int $status = 422): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], $status);
    }

    private function forbidden(Node $servingNode, AuthorizationResult $result, string $permission): JsonResponse
    {
        return $this->error(
            'authorization_failed',
            "This node is not authorized for '{$permission}' on '{$servingNode->name}'.",
            [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $servingNode->name,
            ],
            403,
        );
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /workspaces/setup';
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
