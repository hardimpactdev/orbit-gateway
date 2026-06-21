<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\OrbitContainerNames;
use InvalidArgumentException;

final readonly class AppRuntimeContainerRenderer
{
    public const int InternalPort = 8080;

    /**
     * Canonical name of the Laravel Octane FrankenPHP worker file. Generated
     * by `php artisan octane:install --server=frankenphp` and resolved
     * relative to the app's configured `document_root`.
     */
    public const string WorkerFileName = 'frankenphp-worker.php';

    public function __construct(
        private PhpRuntimePolicy $phpRuntimePolicy,
        private OrbitContainerNames $names,
        private AppRuntimeUser $appRuntimeUser = new AppRuntimeUser,
        private AppDevelopmentPackagesMount $appDevelopmentPackagesMount = new AppDevelopmentPackagesMount,
        private AppRuntimeMountService $appRuntimeMounts = new AppRuntimeMountService,
        private FrankenPhpRuntimeConfigRenderer $frankenPhpConfig = new FrankenPhpRuntimeConfigRenderer,
    ) {}

    public function render(App $app, ?string $preloadPath = null): AppRuntimeContainer
    {
        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            throw new InvalidArgumentException(
                "App '{$app->name}' uses runtime kind '{$app->runtime_kind->value}' and does not get a FrankenPHP runtime container.",
            );
        }

        $policy = $this->phpRuntimePolicy->forVersion($app->php_version, $preloadPath);
        $sourcePath = rtrim((string) $app->path, '/');

        if ($sourcePath === '') {
            throw new InvalidArgumentException("App '{$app->name}' has no source path; cannot render runtime container.");
        }

        $mounts = [
            [
                'source' => $sourcePath,
                'target' => AppRuntimeContainer::SourceTarget,
                'read_only' => false,
            ],
            [
                'source' => $sourcePath,
                'target' => $sourcePath,
                'read_only' => false,
            ],
            [
                'source' => $this->phpIniHostPath($app),
                'target' => AppRuntimeContainer::PhpIniMountTarget,
                'read_only' => true,
            ],
            [
                'source' => "{$sourcePath}/.orbit/frankenphp/data",
                'target' => '/data',
                'read_only' => false,
            ],
            [
                'source' => "{$sourcePath}/.orbit/frankenphp/config",
                'target' => '/config',
                'read_only' => false,
            ],
        ];

        if (($packagesMount = $this->appDevelopmentPackagesMount->forApp($app)) !== null) {
            $mounts[] = $packagesMount;
        }

        foreach ($this->appRuntimeMounts->mountsForRuntime($app) as $mount) {
            $mounts[] = $mount;
        }

        return new AppRuntimeContainer(
            name: $this->containerName($app),
            image: $policy->image,
            network: $this->names->network(),
            restartPolicy: 'unless-stopped',
            appSlug: $app->name,
            runtimeUser: $this->appRuntimeUser->containerUserForApp($app),
            environment: $this->environmentFor($app),
            mounts: $mounts,
            networkAliases: [
                $this->containerName($app),
                "app-{$app->name}",
            ],
            phpIni: $policy->phpIni,
        );
    }

    public function containerName(App $app): string
    {
        return "orbit-app-{$app->name}";
    }

    public function phpIniHostPath(App $app): string
    {
        return "/etc/orbit/apps/{$app->name}.ini";
    }

    public function upstreamUrl(App $app): string
    {
        return 'http://'.$this->containerName($app).':'.self::InternalPort;
    }

    /**
     * @return array<string, string>
     */
    private function environmentFor(App $app): array
    {
        $environment = [
            'SERVER_NAME' => ':'.self::InternalPort,
            'SERVER_ROOT' => $this->documentRootInContainer($app),
            'XDG_CONFIG_HOME' => '/config',
            'XDG_DATA_HOME' => '/data',
            'ORBIT_APP' => $app->name,
            'ORBIT_APP_DOCUMENT_ROOT' => $app->document_root,
            'ORBIT_PHP_VERSION' => $app->php_version,
        ];

        if ($app->worker_enabled) {
            $environment = array_merge($environment, $this->workerEnvironmentFor($app));

            return $environment;
        }

        $frankenPhpConfig = $this->frankenPhpConfig->classic($app);

        if ($frankenPhpConfig !== null) {
            $environment['FRANKENPHP_CONFIG'] = $frankenPhpConfig;
        }

        return $environment;
    }

    /**
     * Worker-mode env vars are emitted only when worker_enabled is true. The
     * stored worker_config is the single source of truth; classic mode is
     * the default and emits none of these vars.
     *
     * Active levers:
     * - `FRANKENPHP_CONFIG`: FrankenPHP natively reads this as a Caddyfile
     *   snippet at boot. App-dev runtimes add thread-pool settings here; worker
     *   mode appends the documented block form against Laravel Octane's
     *   FrankenPHP worker file.
     * - `MAX_REQUESTS`: Laravel's stock `public/frankenphp-worker.php` reads
     *   `$_SERVER['MAX_REQUESTS']` and recycles the worker after that many
     *   requests.
     *
     * @return array<string, string>
     */
    private function workerEnvironmentFor(App $app): array
    {
        $config = $app->workerConfig();
        $workerFile = $this->frankenPhpWorkerFilePath($app);

        return [
            'FRANKENPHP_CONFIG' => $this->frankenPhpConfig->worker($app, $workerFile, $config->workers),
            'MAX_REQUESTS' => (string) $config->maxRequests,
        ];
    }

    public function frankenPhpWorkerFilePath(App $app): string
    {
        return $this->documentRootInContainer($app).'/'.self::WorkerFileName;
    }

    public function documentRootInContainer(App $app): string
    {
        $documentRoot = trim((string) $app->document_root, '/');

        if ($documentRoot === '' || $documentRoot === '.') {
            return AppRuntimeContainer::SourceTarget;
        }

        return AppRuntimeContainer::SourceTarget.'/'.$documentRoot;
    }

    /**
     * Worker file path relative to the app source root. Renderer and the
     * readiness validator must agree on this so what readiness checks
     * matches what the runtime points `FRANKENPHP_CONFIG` at.
     */
    public static function workerFileRelativeToSource(App $app): string
    {
        $documentRoot = trim((string) $app->document_root, '/');

        if ($documentRoot === '' || $documentRoot === '.') {
            return self::WorkerFileName;
        }

        return $documentRoot.'/'.self::WorkerFileName;
    }
}
