<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Apps\PruneAppWorkspaces;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Services\Apps\AppAgentIdeDefaults;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('app:prune', servingNode: ServingNode::AppOwning)]
final class AppPruneController implements Loggable
{
    private ?App $activitySubject = null;

    public function __construct(
        private readonly PruneAppWorkspaces $prune,
        private readonly AppAgentIdeDefaults $defaults,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'app' => ['required', 'string'],
            'dry_run' => ['boolean'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $field = $errors->keys()[0] ?? 'unknown';

            return $this->error('validation_failed', $errors->first(), ['field' => $field], 422);
        }

        $validated = $validator->validated();
        $appName = $validated['app'];
        $dryRun = (bool) ($validated['dry_run'] ?? false);

        $app = $this->resolveApp($appName);

        if (! $app instanceof App) {
            return $this->error('app.not_found', "App '{$appName}' not found.", ['app' => $appName], 404);
        }

        $this->activitySubject = $app;

        $effectiveAdapter = $this->defaults->payloadFor($app)['effective_adapter'];

        if ($effectiveAdapter === null) {
            return $this->error(
                'app.no_agent_ide_adapter',
                'No agent IDE adapter configured for this app.',
                ['app' => $app->name],
                422,
            );
        }

        try {
            $result = $this->prune->handle($app, $dryRun);
        } catch (\RuntimeException $e) {
            return $this->error(
                'app.agent_ide_query_failed',
                $e->getMessage(),
                ['app' => $app->name],
                422,
            );
        }

        $data = [
            'app' => $result['app'],
            'stale_workspaces' => $result['stale_workspaces'],
            'dry_run' => $result['dry_run'],
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

    private function resolveApp(string $name): ?App
    {
        return App::query()
            ->with('node')
            ->get()
            ->filter(fn (App $app): bool => $app->name === $name
                || $app->domain === $name
                || $app->url() === "https://{$name}")
            ->values()
            ->first();
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
        return 'api:POST /apps/prune';
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
