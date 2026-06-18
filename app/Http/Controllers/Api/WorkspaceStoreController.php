<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\CreateWorkspaceProgress;
use App\Contracts\Loggable;
use App\Contracts\ProgressReporter;
use App\Enums\ActivityLogType;
use App\Exceptions\WorkspaceCreateFailed;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\Workspace;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[RequiresPermission('workspace:new', servingNode: ServingNode::AppOwning)]
final class WorkspaceStoreController implements Loggable
{
    private ?Workspace $activitySubject = null;

    public function __construct(
        private readonly CreateWorkspace $createWorkspace,
    ) {}

    public function __invoke(
        Request $request,
        CreateWorkspaceProgress $createProgress,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        if ($this->wantsEventStream($request)) {
            return $this->stream($request, $createProgress, $streams);
        }

        $validator = validator($request->all(), [
            'name' => ['required', 'string', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', 'max:63'],
            'app' => ['required', 'string'],
            'base' => ['nullable', 'string'],
            'php_version' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $field = $errors->keys()[0] ?? 'unknown';

            return $this->error('validation_failed', $errors->first(), ['field' => $field], 422);
        }

        $validated = $validator->validated();

        $name = $validated['name'];
        $appName = $validated['app'];
        $base = $validated['base'] ?? 'main';
        $phpVersion = $validated['php_version'] ?? null;

        if ($name === 'main') {
            return $this->error('validation_failed', "The workspace name 'main' is reserved.", ['field' => 'name'], 422);
        }

        $app = App::query()
            ->with('node')
            ->where('name', $appName)
            ->first();

        if (! $app instanceof App) {
            return $this->error('app.not_found', "App '{$appName}' not found.", ['app' => $appName], 404);
        }

        if ($phpVersion !== null && ! in_array($phpVersion, CreateWorkspace::SUPPORTED_PHP_VERSIONS, true)) {
            return $this->error('validation_failed', 'Unsupported PHP version.', [
                'field' => 'php_version',
                'reason' => 'unsupported_php_version',
            ], 422);
        }

        $existing = Workspace::query()
            ->where('app_id', $app->id)
            ->where('name', $name)
            ->first();

        if ($existing instanceof Workspace) {
            return $this->error('workspace.already_exists', "Workspace '{$name}' already exists for app '{$appName}'.", [
                'name' => $name,
                'app' => $appName,
            ], 422);
        }

        try {
            $result = $this->createWorkspace->handle($app, $name, $base, $phpVersion);
        } catch (WorkspaceCreateFailed $exception) {
            $status = $exception->errorCode === 'workspace.ssh_failure' ? 503 : 422;

            return $this->error($exception->errorCode, $exception->getMessage(), $exception->meta, $status);
        }

        $workspace = Workspace::query()
            ->where('app_id', $app->id)
            ->where('name', $name)
            ->first();
        $this->activitySubject = $workspace instanceof Workspace ? $workspace : null;

        return response()->json([
            'success' => [
                'data' => [
                    'result' => $result['result'],
                    'workspace' => $result['workspace'],
                ],
                'meta' => $result['meta'],
            ],
        ], 201);
    }

    private function stream(
        Request $request,
        CreateWorkspaceProgress $createProgress,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        $validator = validator($request->all(), [
            'name' => ['required', 'string', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', 'max:63'],
            'app' => ['required', 'string'],
            'base' => ['nullable', 'string'],
            'php_version' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $field = $errors->keys()[0] ?? 'unknown';

            return $this->error('validation_failed', $errors->first(), ['field' => $field], 422);
        }

        $validated = $validator->validated();

        $name = $validated['name'];
        $appName = $validated['app'];
        $base = $validated['base'] ?? 'main';
        $phpVersion = $validated['php_version'] ?? null;

        if ($name === 'main') {
            return $this->error('validation_failed', "The workspace name 'main' is reserved.", ['field' => 'name'], 422);
        }

        $app = App::query()
            ->with('node')
            ->where('name', $appName)
            ->first();

        if (! $app instanceof App) {
            return $this->error('app.not_found', "App '{$appName}' not found.", ['app' => $appName], 404);
        }

        if ($phpVersion !== null && ! in_array($phpVersion, CreateWorkspace::SUPPORTED_PHP_VERSIONS, true)) {
            return $this->error('validation_failed', 'Unsupported PHP version.', [
                'field' => 'php_version',
                'reason' => 'unsupported_php_version',
            ], 422);
        }

        $existing = Workspace::query()
            ->where('app_id', $app->id)
            ->where('name', $name)
            ->first();

        if ($existing instanceof Workspace) {
            return $this->error('workspace.already_exists', "Workspace '{$name}' already exists for app '{$appName}'.", [
                'name' => $name,
                'app' => $appName,
            ], 422);
        }

        try {
            $node = $this->createWorkspace->resolveAppNode($app);
        } catch (WorkspaceCreateFailed $exception) {
            $status = $exception->errorCode === 'workspace.ssh_failure' ? 503 : 422;

            return $this->error($exception->errorCode, $exception->getMessage(), $exception->meta, $status);
        }

        return $streams->make(function ($emitter) use ($createProgress, $app, $node, $name, $base, $phpVersion): void {
            $plan = $createProgress->for($app, $node, $name, $base, $phpVersion);
            $exitCode = $plan->runForReporter(app(ProgressReporter::class));

            if ($exitCode !== 0) {
                $failure = $plan->failure() ?? [
                    'code' => 'workspace.enactment_failed',
                    'message' => 'Workspace creation failed.',
                    'meta' => [
                        'step' => 'create',
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

            $result = $plan->result();
            $workspace = Workspace::query()
                ->where('app_id', $app->id)
                ->where('name', $name)
                ->first();
            $this->activitySubject = $workspace instanceof Workspace ? $workspace : null;

            $emitter->complete(0, [
                'footer' => $plan->doneFooter(),
                'result' => $result,
            ]);
        });
    }

    private function wantsEventStream(Request $request): bool
    {
        return in_array('text/event-stream', $request->getAcceptableContentTypes(), true);
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

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /workspaces';
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
