<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Services\Apps\AppRootUpdater;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('app:root', servingNode: ServingNode::AppOwning)]
final class AppRootController implements Loggable
{
    private ?App $activitySubject = null;

    public function __invoke(string $app, Request $request): JsonResponse
    {
        $targetApp = $this->resolveApp($app);

        if (! $targetApp instanceof App) {
            return $this->error('app.not_found', "Application '{$app}' not found.", ['app' => $app], 404);
        }

        $targetApp->loadMissing('node');

        $root = $this->optionalString($request, 'root');

        if ($root === null) {
            return $this->error('validation_failed', 'Root is required.', ['field' => 'root'], 422);
        }

        $result = app(AppRootUpdater::class)->update([
            'app' => $app,
            'root' => $root,
            '--json' => true,
        ]);

        $this->activitySubject = App::query()->where('name', $targetApp->name)->first();

        return response()->json($result->payload, $result->successful() ? 200 : 422);
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

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
        return 'api:POST /apps/{app}/root';
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
            'root' => $this->optionalString(request(), 'root'),
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
