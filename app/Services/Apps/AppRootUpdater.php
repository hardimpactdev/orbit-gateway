<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Actions\Apps\EnactAppRuntime;
use App\Concerns\PromptsForRegistryEntities;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Services\Support\GatewayActionResult;

use function Laravel\Prompts\text;

final class AppRootUpdater
{
    use PromptsForRegistryEntities;

    private const int SUCCESS = 0;

    private const int FAILURE = 1;

    /** @var array<string, mixed> */
    private array $arguments = [];

    private ?string $output = null;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function update(array $arguments): GatewayActionResult
    {
        $this->arguments = $arguments;
        $this->output = null;

        $exitCode = $this->handle(app(EnactAppRuntime::class));

        return GatewayActionResult::fromJsonOutput($exitCode, $this->output);
    }

    private function handle(EnactAppRuntime $enactAppRuntime): int
    {
        $selector = $this->stringArgument('app');
        $root = $this->stringArgument('root');

        if ($selector === null && $this->isInteractiveInput()) {
            $selector = $this->promptAppSelector();

            if ($selector instanceof GatewayApiException) {
                return $this->failCommand(
                    code: $selector->errorCode() ?? 'gateway_unavailable',
                    message: $selector->getMessage(),
                    meta: $selector->errorMeta(),
                );
            }
        }

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        if ($root === null && $this->isInteractiveInput()) {
            $root = trim(text(label: 'Document root', required: true));
        }

        if ($root === null) {
            return $this->failValidation('root', 'Root is required.');
        }

        $app = $this->resolveApp($selector);

        if (! $app instanceof App) {
            return $this->failCommand(
                code: 'app.not_found',
                message: "Application '{$selector}' not found.",
                meta: ['app' => $selector],
            );
        }

        $normalized = $this->normalizeRoot($app, $root);

        if (is_array($normalized)) {
            return $this->failCommand(
                code: 'app.invalid_root',
                message: 'The root path resolves outside the application path.',
                meta: $normalized,
            );
        }

        if (! $this->wantsJson()) {
            return $this->updateRootForHuman($app, $normalized, $enactAppRuntime);
        }

        $changed = $this->applyRootChange($app, $normalized);
        $warnings = $enactAppRuntime->handle($app);

        return $this->successCommand($app->refresh()->load('node'), $changed, $warnings);
    }

    private function promptAppSelector(): string|GatewayApiException
    {
        try {
            return $this->promptForVisibleApp(label: 'Select an app');
        } catch (PromptAborted) {
            return new GatewayApiException('Operation cancelled.', 'validation_failed', []);
        }
    }

    private function updateRootForHuman(App $app, string $normalized, EnactAppRuntime $enactAppRuntime): int
    {
        $changed = $this->applyRootChange($app, $normalized);
        $warnings = $enactAppRuntime->handle($app);

        return $this->successCommand($app->refresh()->load('node'), $changed, $warnings);
    }

    private function applyRootChange(App $app, string $normalized): bool
    {
        $changed = $app->document_root !== $normalized;
        $app->document_root = $normalized;
        $app->save();
        $app->setRelation('node', $app->node);

        return $changed;
    }

    private function resolveApp(string $selector): ?App
    {
        $apps = App::query()
            ->with('node')
            ->get()
            ->filter(fn (App $app): bool => $app->name === $selector
                || $app->domain === $selector
                || $app->url() === "https://{$selector}")
            ->values();

        if ($apps->count() !== 1) {
            return null;
        }

        return $apps->first();
    }

    /**
     * @return string|array{field: string, root: string, resolved_path: string, app_path: string}
     */
    private function normalizeRoot(App $app, string $root): string|array
    {
        $root = trim(str_replace('\\', '/', $root));
        $appPath = rtrim($app->path, '/');

        if ($root === '' || str_starts_with($root, '/')) {
            return $this->invalidRootMeta($root, $appPath, $root);
        }

        $segments = [];

        foreach (explode('/', $root) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $normalized = $segments === [] ? '.' : implode('/', $segments);
        $resolved = $normalized === '.'
            ? $appPath
            : $appPath.'/'.implode('/', $segments);

        if ($root === '..' || str_starts_with($root, '../') || str_contains($root, '/../')) {
            return $this->invalidRootMeta($root, $appPath, $resolved);
        }

        return $normalized;
    }

    /**
     * @return array{field: string, root: string, resolved_path: string, app_path: string}
     */
    private function invalidRootMeta(string $root, string $appPath, string $resolved): array
    {
        return [
            'field' => 'root',
            'root' => $root,
            'resolved_path' => str_starts_with($resolved, '/') ? $resolved : $appPath.'/'.$resolved,
            'app_path' => $appPath,
        ];
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    private function isInteractiveInput(): bool
    {
        return false;
    }

    private function argument(string $key): mixed
    {
        return $this->arguments[$key] ?? null;
    }

    private function option(string $key): mixed
    {
        return $this->arguments["--{$key}"] ?? null;
    }

    private function line(string $message): void
    {
        $this->output = $message;
    }

    private function error(string $message): void
    {
        $this->output = $message;
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     */
    private function successCommand(App $app, bool $changed, array $warnings): int
    {
        return $this->successPayload([
            'app' => $this->appPayload($app),
            'result' => [
                'hostname' => parse_url($app->url(), PHP_URL_HOST) ?: $app->name,
                'changed' => $changed,
            ],
        ], $warnings, (string) $app->node?->name, $changed);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $warnings
     */
    private function successPayload(array $data, array $warnings, string $nodeName, bool $artifactsReenacted): int
    {
        if (! $this->wantsJson()) {
            /** @var array{name?: string, root?: string} $app */
            $app = is_array($data['app'] ?? null) ? $data['app'] : [];
            $result = is_array($data['result'] ?? null) ? $data['result'] : [];
            $changed = (bool) ($result['changed'] ?? false);

            $this->line($changed
                ? "SUCCESS: Document root for app '".(string) ($app['name'] ?? '')."' updated to '".(string) ($app['root'] ?? '')."'."
                : "SUCCESS: Document root for app '".(string) ($app['name'] ?? '')."' is already '".(string) ($app['root'] ?? '')."'.");
            $this->line("Artifacts successfully re-enacted on node '{$nodeName}'.");

            if ($warnings !== []) {
                foreach ($warnings as $warning) {
                    $this->line('WARNING: '.(string) ($warning['message'] ?? $warning['code'] ?? 'Warning'));
                    $this->line('  Code:  '.(string) ($warning['code'] ?? 'warning'));

                    if (isset($warning['next_command']) && is_string($warning['next_command'])) {
                        $this->line('  Next:  orbit '.$warning['next_command']);
                    }
                }
            }

            return self::SUCCESS;
        }

        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => [
                    'node' => $nodeName,
                    'artifacts_reenacted' => $artifactsReenacted,
                    'warnings' => $warnings,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
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

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if (! $this->wantsJson()) {
            $this->error($message);

            return self::FAILURE;
        }

        $this->line(json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }

    private function failValidation(string $field, string $message): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: ['field' => $field],
        );
    }
}
