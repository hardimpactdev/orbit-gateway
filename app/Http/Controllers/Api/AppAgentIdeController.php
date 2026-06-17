<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Apps\PruneAppWorkspaces;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Api\SetAppAgentIdeApiRequest;
use App\Models\App;
use App\Services\Apps\AppAgentIdeDefaults;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

#[RequiresPermission('app:agent', servingNode: ServingNode::AppOwning)]
final class AppAgentIdeController implements Loggable
{
    private ?App $activitySubject = null;

    private ?string $activityTargetName = null;

    private ?string $activityAgentIde = null;

    private ?string $activityAction = null;

    public function __construct(
        private readonly AppAgentIdeDefaults $defaults,
        private readonly PruneAppWorkspaces $pruneAppWorkspaces,
    ) {}

    public function __invoke(SetAppAgentIdeApiRequest $request, string $app): JsonResponse
    {
        $this->activityTargetName = $app;

        $targetApp = $this->resolveApp($app);

        if (! $targetApp instanceof App) {
            return $this->error(
                code: 'app.not_found',
                message: "App '{$app}' not found.",
                meta: ['app' => $app],
                status: 404,
            );
        }

        $targetApp->loadMissing('node');

        $agentIde = $request->agentIde();

        if (! $this->defaults->isSupported($agentIde)) {
            return $this->error(
                code: 'app.unsupported_adapter',
                message: "The adapter \"{$agentIde}\" is not supported.",
                meta: [
                    'adapter' => $agentIde,
                    'supported' => $this->defaults->supportedAdapters(),
                ],
                status: 422,
            );
        }

        $data = $this->defaults->set($targetApp, $agentIde);

        if ($data['action'] === 'set') {
            $cleanupResult = $this->maybeCleanupWorkspaces($targetApp, $data, $request->force());

            if (isset($cleanupResult['error'])) {
                return response()->json($cleanupResult, 422);
            }

            $data = $cleanupResult;
        }

        $this->activitySubject = $targetApp->refresh();
        $this->activityAgentIde = $data['agent_ide']['effective_adapter'] ?? $data['agent_ide']['adapter'];
        $this->activityAction = $data['action'];

        return response()->json([
            'success' => [
                'data' => $data,
            ],
        ]);
    }

    /**
     * @param  array{
     *     app: array<string, mixed>,
     *     agent_ide: array{adapter: string|null, source: string, effective_adapter: string|null},
     *     cleanup: array{workspaces_removed: list<string>},
     *     action: string,
     *     previous_adapter: string|null,
     * }  $data
     * @return array{
     *     app: array<string, mixed>,
     *     agent_ide: array{adapter: string|null, source: string, effective_adapter: string|null},
     *     cleanup: array{workspaces_removed: list<string>},
     *     action: string,
     *     previous_adapter: string|null,
     * }
     */
    private function maybeCleanupWorkspaces(App $app, array $data, bool $force): array
    {
        $previousAdapter = $data['previous_adapter'];
        $currentEffective = $data['agent_ide']['effective_adapter'];

        if ($previousAdapter === null || $previousAdapter === $currentEffective) {
            return $data;
        }

        try {
            $dryRun = $this->pruneAppWorkspaces->handle($app, dryRun: true, adapterName: $previousAdapter);
            $staleWorkspaces = $dryRun['stale_workspaces'];

            if ($staleWorkspaces === []) {
                return $data;
            }

            if (! $force) {
                $count = count($staleWorkspaces);

                return $this->error(
                    code: 'workspace_cleanup_consent_required',
                    message: "Destructive workspace cleanup required ({$count} workspace(s) managed by '{$previousAdapter}'). Use force=true to proceed.",
                    meta: [
                        'previous_adapter' => $previousAdapter,
                        'stale_workspaces' => array_map(fn (array $ws): string => $ws['name'], $staleWorkspaces),
                    ],
                    status: 422,
                )->getData(true);
            }

            $result = $this->pruneAppWorkspaces->handle($app, dryRun: false, adapterName: $previousAdapter);
            $removed = array_values(array_filter(
                $result['stale_workspaces'],
                fn (array $ws): bool => $ws['removed'],
            ));

            $data['cleanup']['workspaces_removed'] = array_map(
                fn (array $ws): string => $ws['name'],
                $removed,
            );
        } catch (\RuntimeException) {
            // Adapter does not support workspace discovery; skip cleanup.
        }

        return $data;
    }

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->with('node')
            ->get()
            ->filter(fn (App $app): bool => $app->name === $selector
                || $app->domain === $selector
                || $app->url() === "https://{$selector}")
            ->values()
            ->first();
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
        return 'api:POST /apps/{app}/agent-ide';
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
        return [
            'target_app' => $this->activityTargetName ?? (string) request()->route('app'),
            'agent_ide' => $this->activityAgentIde,
            'action' => $this->activityAction,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        $target = $this->activityTargetName ?? (string) request()->route('app');

        if ($target === '' || $this->activityAction === null) {
            return null;
        }

        if ($this->activityAgentIde === null) {
            return "App {$target} agent IDE cleared";
        }

        if ($this->activityAction === 'converged') {
            return "App {$target} agent IDE already set to {$this->activityAgentIde}";
        }

        return "App {$target} agent IDE set to {$this->activityAgentIde}";
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
