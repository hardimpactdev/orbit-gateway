<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AgentIdeMessageAdapter;
use App\Contracts\AgentIdeWorkspacePathResolver;
use App\Contracts\OpenCodeClientFactory;
use App\Contracts\ProgressReporter;
use App\Contracts\RemoteShell;
use App\Contracts\RemoteShellStream;
use App\Contracts\RequestProfiler;
use App\Contracts\SiteCertificateInstaller;
use App\Contracts\StartsRemoteShellProcesses;
use App\Contracts\UpdateAllGatewayStream;
use App\Contracts\WorkspaceSourceDrivers;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\UpdateAllGatewayStreamClient;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\AgentIde\CoreAgentIdeMessageAdapter;
use App\Services\AgentIde\CoreAgentIdeWorkspacePathResolver;
use App\Services\AgentIde\SdkOpenCodeClientFactory;
use App\Services\Ca\OrbitSiteCertificateInstaller;
use App\Services\CurlRequestProfiler;
use App\Services\Dns\DnsmasqConfigBuilder;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Dns\LocalResolver;
use App\Services\Dns\OrbitDnsServiceInstaller;
use App\Services\Doctor\DnsRuntimeProbe;
use App\Services\Operations\OperationResultRegistry;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Operations\OperationTokenIntrospector;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteHostExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\SshRemoteShellStream;
use App\Services\Tools\ToolDefinitionRegistry;
use App\Services\Trust\LinuxTrustStoreInstaller;
use App\Services\Trust\MacOsTrustStoreInstaller;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Updates\UnattendedUpgradesDriver;
use App\Services\Updates\UpdateDriverRegistry;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use App\Services\Vpn\VpnDnsSwarmManager;
use App\Services\Vpn\VpnNodeResolver;
use App\Services\Vpn\WgEasyServiceInstaller;
use App\Services\Vpn\WgEasyVpnBackend;
use App\Services\WebSockets\WebSocketRoleBaselineTiming;
use App\Services\Workspaces\PolyscopeWorkspaceBranchAligner;
use App\Services\Workspaces\PolyscopeWorkspaceDriver;
use App\Services\Workspaces\WorkspaceSourceDriverResolver;
use App\Support\LocalPlatform;
use App\Support\Streaming\NullProgressReporter;
use App\Tools\CaddyTool;
use App\Tools\ComposerTool;
use App\Tools\DnsTool;
use App\Tools\DockerTool;
use App\Tools\GhTool;
use App\Tools\HermesTool;
use App\Tools\LaravelInstallerTool;
use App\Tools\MailpitTool;
use App\Tools\OpenClawTool;
use App\Tools\OpenCodeServerTool;
use App\Tools\PhpCliTool;
use App\Tools\PhpTool;
use App\Tools\PolyscopeServerTool;
use App\Tools\ReverbTool;
use App\Tools\SeaweedfsTool;
use App\Tools\VitePlusTool;
use Illuminate\Support\ServiceProvider;
use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Security\OperationTokenVerifier;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $_SERVER['PAO_DISABLE'] ??= '1';

        $this->app->scoped(ActivityLogCorrelation::class);
        $this->app->scoped(WebSocketRoleBaselineTiming::class);
        $this->app->singleton(OperationResultRegistry::class);
        $this->app->bind(OperationTokenFactory::class, fn ($app): OperationTokenFactory => new OperationTokenFactory(
            signer: $app->make(OperationTokenSigner::class),
            secret: $this->operationTokenSigningKey(),
            ttlSeconds: $this->operationTokenTtlSeconds(),
        ));
        $this->app->bind(OperationTokenIntrospector::class, fn ($app): OperationTokenIntrospector => new OperationTokenIntrospector(
            verifier: $app->make(OperationTokenVerifier::class),
            secret: $this->operationTokenSigningKey(),
        ));
        $this->app->singleton(GatewayConnector::class);
        $this->app->singleton(LocalResolver::class);
        $this->app->bind(ProgressReporter::class, NullProgressReporter::class);
        $this->app->bind(AgentIdeMessageAdapter::class, CoreAgentIdeMessageAdapter::class);
        $this->app->bind(OpenCodeClientFactory::class, SdkOpenCodeClientFactory::class);
        $this->app->bind(AgentIdeWorkspacePathResolver::class, fn ($app): CoreAgentIdeWorkspacePathResolver => new CoreAgentIdeWorkspacePathResolver(
            localExecutor: $app->make(RemoteLocalExecutor::class),
        ));
        $this->app->bind(RequestProfiler::class, CurlRequestProfiler::class);
        $this->app->bind(RemoteExecutor::class, RemoteHostExecutor::class);
        $this->app->bind(RemoteShell::class, RemoteHostExecutor::class);
        $this->app->bind(StartsRemoteShellProcesses::class, RemoteHostExecutor::class);
        $this->app->bind(RemoteLocalExecutor::class, fn ($app): RemoteLocalExecutor => new RemoteLocalExecutor(
            transport: $app->make(RemoteHostExecutor::class),
            commands: $app->make(LocalExecutorCommandBuilder::class),
            operationTokens: $app->make(OperationTokenFactory::class),
            activityLogger: $app->make(ActivityLogger::class),
            operationRuns: $app->make(OperationRunRecorder::class),
            operationTokenSecret: $this->operationTokenSigningKey(),
        ));
        $this->app->bind(RemoteShellStream::class, SshRemoteShellStream::class);
        $this->app->bind(PolyscopeWorkspaceDriver::class, fn ($app): PolyscopeWorkspaceDriver => new PolyscopeWorkspaceDriver(
            branchAligner: $app->make(PolyscopeWorkspaceBranchAligner::class),
            localExecutor: $app->make(RemoteLocalExecutor::class),
        ));
        $this->app->bind(SiteCertificateInstaller::class, OrbitSiteCertificateInstaller::class);
        $this->app->bind(UpdateAllGatewayStream::class, UpdateAllGatewayStreamClient::class);
        $this->app->bind(WorkspaceSourceDrivers::class, WorkspaceSourceDriverResolver::class);
        $this->app->singleton(ToolDefinitionRegistry::class, fn ($app): ToolDefinitionRegistry => new ToolDefinitionRegistry([
            $app->make(CaddyTool::class),
            $app->make(DockerTool::class),
            $app->make(VitePlusTool::class),
            $app->make(PhpCliTool::class),
            $app->make(GhTool::class),
            $app->make(ComposerTool::class),
            $app->make(DnsTool::class),
            $app->make(PhpTool::class),
            $app->make(MailpitTool::class),
            $app->make(ReverbTool::class),
            $app->make(SeaweedfsTool::class),
            $app->make(PolyscopeServerTool::class),
            $app->make(OpenCodeServerTool::class),
            $app->make(OpenClawTool::class),
            $app->make(HermesTool::class),
            $app->make(LaravelInstallerTool::class),
        ]));
        $this->app->singleton(UpdateDriverRegistry::class, fn ($app): UpdateDriverRegistry => new UpdateDriverRegistry([
            $app->make(UnattendedUpgradesDriver::class),
        ]));

        $this->app->bind(WgEasyVpnBackend::class, fn ($app): WgEasyVpnBackend => new WgEasyVpnBackend(
            username: (string) config('services.wg_easy.username', config('orbit.wg_easy.username', 'orbit')),
            password: (string) config('services.wg_easy.password', config('orbit.wg_easy.password', '')),
            localExecutor: $this->hasOperationTokenSigningKey() ? $app->make(RemoteLocalExecutor::class) : null,
            vpnNodeResolver: $app->make(VpnNodeResolver::class),
        ));

        $this->app->singleton(WgEasyServiceInstaller::class, fn ($app): WgEasyServiceInstaller => new WgEasyServiceInstaller(
            rootPath: $this->orbitConfigPath(),
            statePath: $this->wgEasyStatePath(),
            localExecutor: $this->hasOperationTokenSigningKey() ? $app->make(RemoteLocalExecutor::class) : null,
            vpnNodeResolver: $app->make(VpnNodeResolver::class),
        ));

        $this->app->singleton(VpnDnsSwarmInstaller::class, fn ($app): VpnDnsSwarmInstaller => new VpnDnsSwarmInstaller(
            rootPath: $this->orbitConfigPath(),
            statePath: $this->orbitConfigPath().'/wg-easy',
            localExecutor: $this->hasOperationTokenSigningKey() ? $app->make(RemoteLocalExecutor::class) : null,
            vpnNodeResolver: $app->make(VpnNodeResolver::class),
        ));

        $this->app->singleton(OrbitDnsServiceInstaller::class, fn ($app): OrbitDnsServiceInstaller => new OrbitDnsServiceInstaller(
            configBuilder: $app->make(DnsmasqConfigBuilder::class),
            rootPath: $this->orbitConfigPath(),
        ));

        $this->app->singleton(DnsmasqReconciler::class, fn ($app): DnsmasqReconciler => new DnsmasqReconciler(
            configBuilder: $app->make(DnsmasqConfigBuilder::class),
            rootPath: $this->orbitConfigPath(),
            swarmManager: $app->make(VpnDnsSwarmManager::class),
        ));

        $this->app->singleton(DnsRuntimeProbe::class, fn ($app): DnsRuntimeProbe => new DnsRuntimeProbe(
            configBuilder: $app->make(DnsmasqConfigBuilder::class),
            rootPath: $this->orbitConfigPath(),
        ));

        $this->app->bind(TrustStoreInstaller::class, function ($app): TrustStoreInstaller {
            $platform = $app->make(LocalPlatform::class);

            return match ($platform->current()) {
                'macos' => new MacOsTrustStoreInstaller,
                'linux' => new LinuxTrustStoreInstaller,
                default => throw new RuntimeException('Unsupported platform for trust store operations.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(base_path('database/migrations'));
    }

    private function orbitConfigPath(): string
    {
        $configured = config('orbit.paths.config_root');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            $home = '/root';
        }

        return $home.'/.config/orbit';
    }

    private function operationTokenSigningKey(): string
    {
        $secret = config('app.key');

        if (! is_string($secret) || trim($secret) === '') {
            throw new RuntimeException('Application key is not configured for operation token signing.');
        }

        return $secret;
    }

    private function hasOperationTokenSigningKey(): bool
    {
        $secret = config('app.key');

        return is_string($secret) && trim($secret) !== '';
    }

    private function operationTokenTtlSeconds(): int
    {
        $ttlSeconds = config('orbit.operation_token_ttl_seconds');

        if (is_int($ttlSeconds)) {
            return $this->validateOperationTokenTtlSeconds($ttlSeconds);
        }

        if (is_string($ttlSeconds) && ctype_digit($ttlSeconds)) {
            return $this->validateOperationTokenTtlSeconds((int) $ttlSeconds);
        }

        throw new RuntimeException('Operation token TTL is not configured.');
    }

    private function validateOperationTokenTtlSeconds(int $ttlSeconds): int
    {
        if ($ttlSeconds < 1) {
            throw new RuntimeException('Operation token TTL is not configured.');
        }

        return $ttlSeconds;
    }

    private function wgEasyStatePath(): string
    {
        $databasePath = config('services.wg_easy.database_path');

        if (is_string($databasePath) && $databasePath !== '') {
            return rtrim(dirname($databasePath), '/');
        }

        return '/home/orbit/.wg-easy';
    }
}
