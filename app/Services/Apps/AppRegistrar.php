<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Actions\Apps\EnactAppRuntime;
use App\Concerns\PromptsForRegistryEntities;
use App\Contracts\RemoteShell;
use App\Enums\Nodes\NodeStatus;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Support\GatewayActionResult;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

final class AppRegistrar
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
    public function register(array $arguments): GatewayActionResult
    {
        $this->arguments = $arguments;
        $this->output = null;

        $exitCode = $this->handle(app(EnactAppRuntime::class));

        return GatewayActionResult::fromJsonOutput($exitCode, $this->output);
    }

    private function handle(EnactAppRuntime $enactAppRuntime): int
    {
        $input = $this->resolveInput();

        if (is_int($input)) {
            return $input;
        }

        $existingApp = App::query()
            ->with('node')
            ->where('name', $input['name'])
            ->first();

        $requiredRole = $input['domain'] !== null ? 'app-prod' : 'app-dev';
        $node = $this->resolveTargetNode($input['node'], $existingApp, $requiredRole);

        if (is_int($node)) {
            return $node;
        }

        $path = $input['path'] ?? $existingApp?->path;

        if ((! is_string($path) || $path === '') && $this->isInteractiveInput()) {
            $path = trim(text(label: 'App path on node', required: true));
        }

        if (! is_string($path) || $path === '') {
            return $this->failValidation('path', 'The --path option is required when registering an unmanaged app.');
        }

        if (! str_starts_with($path, '/')) {
            return $this->failValidation('path', 'Path must be absolute.');
        }

        if (! $existingApp instanceof App && $this->isInteractiveInput() && ! confirm('Adopt existing app path?', default: true)) {
            return $this->failValidation('path', 'App path adoption was cancelled.');
        }

        $pathProbe = app(RemoteShell::class)->run($node, sprintf('test -d %s', escapeshellarg($path)));

        if (! $pathProbe->successful()) {
            return $this->failValidation('path', "Path '{$path}' does not exist on node '{$node->name}'.");
        }

        $pathOwner = App::query()
            ->where('node_id', $node->id)
            ->where('path', $path)
            ->where('name', '!=', $input['name'])
            ->first();

        if ($pathOwner instanceof App) {
            return $this->failCommand(
                code: 'app.path_collision',
                message: "Path '{$path}' on node '{$node->name}' is already owned by app '{$pathOwner->name}'.",
                meta: [
                    'path' => $path,
                    'existing_app' => $pathOwner->name,
                    'node' => $node->name,
                ],
            );
        }

        $explicitMove = $this->isExplicitMove($input, $path, $node, $existingApp);

        if ($existingApp instanceof App && $existingApp->node_id !== $node->id && ! $explicitMove) {
            return $this->failCommand(
                code: 'app.path_collision',
                message: "App '{$input['name']}' is already registered on node '{$existingApp->node?->name}'.",
                meta: [
                    'path' => $existingApp->path,
                    'existing_app' => $existingApp->name,
                    'node' => $existingApp->node?->name,
                ],
            );
        }

        if ($existingApp instanceof App && $existingApp->path !== $path && ! $explicitMove) {
            return $this->failCommand(
                code: 'app.path_collision',
                message: "App '{$input['name']}' is already registered at '{$existingApp->path}'.",
                meta: [
                    'path' => $path,
                    'existing_app' => $existingApp->name,
                    'node' => $node->name,
                ],
            );
        }

        $routeConflict = $this->routeConflict($input, $node, $existingApp);

        if ($routeConflict instanceof ProxyRoute) {
            return $this->failCommand(
                code: 'proxy.domain_conflict',
                message: "Proxy route domain '{$routeConflict->domain}' is already registered.",
                meta: [
                    'domain' => $routeConflict->domain,
                    'owner_type' => $routeConflict->owner_type,
                    'kind' => $routeConflict->kind,
                ],
            );
        }

        if (! $this->wantsJson()) {
            return $this->registerForHuman($input, $node, $path, $existingApp, $enactAppRuntime);
        }

        $action = $this->registrationAction($existingApp, $explicitMove);
        $app = $this->registerAppRecord($input, $node, $path, $existingApp);
        $warnings = $enactAppRuntime->handle($app);

        return $this->successCommand([
            'result' => ['action' => $action],
            'app' => $this->appPayload($app),
        ], $warnings, $node->name);
    }

    /**
     * @param  array{name: string, node: ?string, path: ?string, root: string, php_version: string, domain: ?string}  $input
     */
    private function registerForHuman(
        array $input,
        Node $node,
        string $path,
        ?App $existingApp,
        EnactAppRuntime $enactAppRuntime,
    ): int {
        $action = $this->registrationAction(
            $existingApp,
            $this->isExplicitMove($input, $path, $node, $existingApp),
        );
        $app = $this->registerAppRecord($input, $node, $path, $existingApp);
        $warnings = $enactAppRuntime->handle($app);

        return $this->successCommand([
            'result' => ['action' => $action],
            'app' => $this->appPayload($app),
        ], $warnings, $node->name);
    }

    /**
     * @param  array{name: string, node: ?string, path: ?string, root: string, php_version: string, domain: ?string}  $input
     */
    private function isExplicitMove(array $input, string $path, Node $node, ?App $existingApp): bool
    {
        if (! $existingApp instanceof App) {
            return false;
        }

        if ($input['node'] === null || $input['path'] === null) {
            return false;
        }

        return $existingApp->node_id !== $node->id || $existingApp->path !== $path;
    }

    private function registrationAction(?App $existingApp, bool $explicitMove): string
    {
        if (! $existingApp instanceof App) {
            return 'adopted';
        }

        return $explicitMove ? 'moved' : 'converged';
    }

    /**
     * @param  array{name: string, node: ?string, path: ?string, root: string, php_version: string, domain: ?string}  $input
     */
    private function registerAppRecord(array $input, Node $node, string $path, ?App $existingApp): App
    {
        $app = App::query()->updateOrCreate(
            ['name' => $input['name']],
            [
                'node_id' => $node->id,
                'environment' => $input['domain'] !== null ? 'production' : 'development',
                'domain' => $input['domain'] ?? $existingApp?->domain,
                'path' => $path,
                'document_root' => $input['root'],
                'repository' => $existingApp?->repository,
                'php_version' => $input['php_version'],
                'adopted' => $existingApp instanceof App ? $existingApp->adopted : true,
            ],
        );

        $app->setRelation('node', $node);

        return $app;
    }

    /**
     * @return array{name: string, node: ?string, path: ?string, root: string, php_version: string, domain: ?string}|int
     */
    private function resolveInput(): array|int
    {
        $name = $this->stringArgument('name');
        if ($name === null && $this->isInteractiveInput()) {
            $name = trim(text(label: 'App name', required: true));
        }

        if ($name === null) {
            return $this->failValidation('name', 'App name is required.');
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) || mb_strlen($name) > 40) {
            return $this->failValidation('name', 'App name must be a slug of 40 characters or fewer.');
        }

        $path = $this->stringOption('path');

        if ($path !== null && ! str_starts_with($path, '/')) {
            return $this->failValidation('path', 'Path must be absolute.');
        }

        $root = $this->stringOption('root') ?? 'public';
        $phpVersion = $this->stringOption('php-version') ?? PhpRuntimeCatalog::DEFAULT;
        $domain = $this->stringOption('domain');

        if ($root === '' || preg_match('/[\x00-\x1F;`$|&<>"\'\\\\]/', $root)) {
            return $this->failValidation('root', 'Document root is invalid.');
        }

        if (! in_array($phpVersion, PhpRuntimeCatalog::SUPPORTED, true)) {
            return $this->failValidation('php_version', 'Unsupported PHP version.');
        }

        if ($domain !== null && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return $this->failValidation('domain', 'Production domain is invalid.');
        }

        return [
            'name' => $name,
            'node' => $this->stringOption('node'),
            'path' => $path,
            'root' => $root,
            'php_version' => $phpVersion,
            'domain' => $domain,
        ];
    }

    private function resolveTargetNode(?string $nodeName, ?App $existingApp, string $requiredRole): Node|int
    {
        if ($nodeName === null && $existingApp instanceof App) {
            $existingApp->loadMissing('node');
            $node = $existingApp->node;

            if ($node instanceof Node) {
                return $this->ensureEligibleNode($node, $requiredRole);
            }
        }

        if ($nodeName === null) {
            if ($this->isInteractiveInput()) {
                try {
                    $selectedNode = $this->promptForVisibleNode(
                        label: 'Select target app node',
                        role: 'app-host',
                    );
                } catch (PromptAborted) {
                    return $this->failValidation('node', 'Operation cancelled.');
                }

                if ($selectedNode instanceof GatewayApiException) {
                    return $this->failCommand(
                        code: $selectedNode->errorCode() ?? 'gateway_unavailable',
                        message: $selectedNode->getMessage(),
                        meta: $selectedNode->errorMeta(),
                    );
                }

                $nodeName = $selectedNode;
            }

            if ($nodeName === null) {
                $nodeNames = Node::query()
                    ->whereIn('id', app(NodeRoleAssignments::class)->activeAppHostNodeIds())
                    ->where('status', NodeStatus::Active->value)
                    ->orderBy('name')
                    ->pluck('name')
                    ->all();

                if (count($nodeNames) !== 1) {
                    return $this->failValidation('node', 'The --node option is required when the target app node cannot be inferred.');
                }

                $nodeName = (string) $nodeNames[0];
            }
        }

        $node = Node::query()->where('name', $nodeName)->first();

        if (! $node instanceof Node) {
            return $this->failValidation('node', "Node '{$nodeName}' was not found.");
        }

        return $this->ensureEligibleNode($node, $requiredRole);
    }

    private function ensureEligibleNode(Node $node, string $requiredRole): Node|int
    {
        if ($node->isActive() && app(NodeRoleAssignments::class)->nodeHasActiveRole($node, $requiredRole)) {
            return $node;
        }

        return $this->failCommand(
            code: 'app.ineligible_node',
            message: "Node '{$node->name}' is not an active app node.",
            meta: [
                'node' => $node->name,
                'required_role' => $requiredRole,
                'status' => $node->status->value,
            ],
        );
    }

    /**
     * @param  array{name: string, node: ?string, path: ?string, root: string, php_version: string, domain: ?string}  $input
     */
    private function routeConflict(array $input, Node $node, ?App $existingApp): ?ProxyRoute
    {
        $domain = $input['domain'] ?? ($existingApp instanceof App ? $existingApp->domain : null) ?? $this->developmentDomain($input['name'], $node);

        $route = ProxyRoute::query()->where('domain', $domain)->first();

        if (! $route instanceof ProxyRoute) {
            return null;
        }

        if ($existingApp instanceof App && $route->app_id === $existingApp->id) {
            return null;
        }

        return $route;
    }

    private function developmentDomain(string $name, Node $node): string
    {
        $tld = is_string($node->tld) ? trim($node->tld, '.') : '';

        if ($tld === '') {
            return $name;
        }

        return "{$name}.{$tld}";
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

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
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $warnings
     */
    private function successCommand(array $data, array $warnings, string $nodeName): int
    {
        if (! $this->wantsJson()) {
            /** @var array{name?: string, url?: string} $app */
            $app = is_array($data['app'] ?? null) ? $data['app'] : [];
            $action = (string) ($data['result']['action'] ?? '');

            $this->line($this->successLine($action, $app));
            $this->line('URL: '.(string) ($app['url'] ?? ''));

            if ($warnings !== []) {
                $this->line('Warnings:');

                foreach ($warnings as $warning) {
                    $this->line('- '.(string) ($warning['message'] ?? $warning['code'] ?? 'Warning'));

                    if (isset($warning['next_command']) && is_string($warning['next_command'])) {
                        $this->line('  Retry with: orbit '.$warning['next_command']);
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
                    'warnings' => $warnings,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function successLine(string $action, array $app): string
    {
        $name = (string) ($app['name'] ?? '');
        $node = (string) ($app['node'] ?? '');
        $path = (string) ($app['path'] ?? '');

        return match ($action) {
            'adopted' => "App '{$name}' successfully adopted from path '{$path}' on node '{$node}'.",
            'converged' => "App '{$name}' is already converged on node '{$node}'. No changes were needed.",
            default => "App '{$name}' successfully registered on node '{$node}'.",
        };
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
