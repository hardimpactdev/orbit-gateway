<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Apps\RemoveApp;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('app:remove', servingNode: ServingNode::AppOwning)]
final class AppRemoveController implements Loggable
{
    private ?App $activitySubject = null;

    public function __invoke(string $app, Request $request, RemoveApp $removeApp): JsonResponse
    {
        if ($request->boolean('destructive_consent') !== true) {
            return $this->error('validation_failed', 'Use --force to remove this app.', ['field' => 'force'], 422);
        }

        $targetApp = $this->resolveApp($app);

        if (! $targetApp instanceof App) {
            return $this->error('app.not_found', "App '{$app}' not found.", ['name' => $app], 404);
        }

        $targetApp->loadMissing('node');

        $this->activitySubject = $targetApp;
        $result = $removeApp->handle($targetApp);
        $payload = [
            'success' => [
                'data' => [
                    'app' => $result['app'],
                    'result' => $result['result'],
                    'cleanup' => $result['cleanup'],
                ],
            ],
        ];

        if ($result['warnings'] !== []) {
            $payload['success']['meta'] = [
                'warnings' => $result['warnings'],
            ];
        }

        return response()->json($payload);
    }

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->with(['node', 'processes'])
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
        return ActivityLogType::Destructive;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:DELETE /apps/{app}';
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
            'name' => request()->route('app'),
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
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
