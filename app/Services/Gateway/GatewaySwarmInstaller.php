<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Enums\Gateway\GatewayExposureMode;
use App\Services\Ca\OrbitCaService;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Tools\CaddyTool;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class GatewaySwarmInstaller
{
    private const string GatewayCertPath = '/etc/orbit/certs/gateway.crt';

    private const string GatewayKeyPath = '/etc/orbit/certs/gateway.key';

    public function __construct(
        private readonly OrbitCaService $caService = new OrbitCaService,
        private readonly GatewaySwarmManager $swarm = new GatewaySwarmManager,
        private readonly GatewaySwarmStackRenderer $stackRenderer = new GatewaySwarmStackRenderer,
        private readonly GatewayImageAcquirer $imageAcquirer = new GatewayImageAcquirer,
        private readonly GatewayDirectFirewallInstaller $directFirewall = new GatewayDirectFirewallInstaller,
        private readonly GatewayExposureTransitionGuard $transitionGuard = new GatewayExposureTransitionGuard,
        private readonly CaddyTool $caddyTool = new CaddyTool,
        private readonly GatewayCaddyRouteRenderer $gatewayRouteRenderer = new GatewayCaddyRouteRenderer,
    ) {}

    public function install(
        string $wireguardAddress,
        GatewayImageReference $image,
        GatewayExposureMode $exposureMode,
        ?string $configRoot = null,
        string $wireguardCidr = '10.6.0.0/24',
        string $wireguardInterface = 'wg-orbit',
        ?string $imageArchive = null,
    ): void {
        if (filter_var($wireguardAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException("Invalid WireGuard API address: {$wireguardAddress}");
        }

        $configRoot = $this->configRoot($configRoot);

        File::ensureDirectoryExists("{$configRoot}/certs", 0700);
        $this->bootstrapConfigRoot($configRoot);

        $gatewayLeaf = $this->caService->issueLeaf('gateway', [$wireguardAddress]);
        $this->imageAcquirer->ensure($image, $imageArchive);

        if ($exposureMode->isGatewayDirect()) {
            $this->directFirewall->install($wireguardCidr, $wireguardInterface);
        }

        $this->swarm->ensureSwarm();
        $this->swarm->ensureGatewayNodeLabel();
        $this->swarm->ensureAttachableOverlayNetwork();

        if ($exposureMode->isGatewayDirect()) {
            $this->transitionGuard->assertPublicPortsReleased();
        }

        $stackPath = $this->swarm->writeStackFile(
            $this->stackRenderer->render($image, $exposureMode, $configRoot),
        );

        $this->swarm->deployStack($stackPath);

        if ($exposureMode->isRouterColocated()) {
            $this->transitionGuard->assertPublicPortsReleased();
            $this->convergeRouterOwnedOrbitCaddy($wireguardAddress, $wireguardCidr, $gatewayLeaf);
        }
    }

    /**
     * @param  array{cert: string, key: string}  $gatewayLeaf
     */
    private function convergeRouterOwnedOrbitCaddy(string $wireguardAddress, string $wireguardCidr, array $gatewayLeaf): void
    {
        $this->installCaddyReadableGatewayLeaf($gatewayLeaf);

        $container = OrbitCaddyContainer::forPublicIngress($wireguardAddress);

        $this->runShellScript(
            $this->caddyTool->updateScript(['container' => $container->spec()]),
            'converge router-owned orbit-caddy container',
        );

        $this->runRequiredWithInput(
            'sudo tee /etc/caddy/orbit/orbit-gateway.caddy > /dev/null',
            $this->gatewayRouteRenderer->render(
                serverNames: [$wireguardAddress, ':443'],
                wireguardCidr: $wireguardCidr,
                certPath: self::GatewayCertPath,
                keyPath: self::GatewayKeyPath,
            ),
            'write router-colocated gateway Caddy route',
        );

        $this->assertRouterCaddyCanReadGatewayLeaf();

        $this->runRequired(CaddyTool::reloadCommand('orbit-caddy'), 'reload router-owned orbit-caddy container');
    }

    private function assertRouterCaddyCanReadGatewayLeaf(): void
    {
        $this->runRequired(
            sprintf('docker exec %s test -r %s', escapeshellarg('orbit-caddy'), escapeshellarg(self::GatewayCertPath)),
            'verify router-owned orbit-caddy can read gateway certificate',
        );
        $this->runRequired(
            sprintf('docker exec %s test -r %s', escapeshellarg('orbit-caddy'), escapeshellarg(self::GatewayKeyPath)),
            'verify router-owned orbit-caddy can read gateway certificate key',
        );
    }

    /**
     * @param  array{cert: string, key: string}  $leaf
     */
    private function installCaddyReadableGatewayLeaf(array $leaf): void
    {
        $this->runRequired('sudo install -d -m 0755 /etc/orbit/certs', 'prepare Orbit Caddy certificate directory');
        $this->runRequired(
            sprintf('sudo install -m 0644 %s %s', escapeshellarg($leaf['cert']), escapeshellarg(self::GatewayCertPath)),
            'install gateway certificate for router-owned orbit-caddy',
        );
        $this->runRequired(
            sprintf('sudo install -m 0644 %s %s', escapeshellarg($leaf['key']), escapeshellarg(self::GatewayKeyPath)),
            'install gateway certificate key for router-owned orbit-caddy',
        );
    }

    private function runRequired(string $command, string $step): void
    {
        $result = Process::timeout(60)->run($command);

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Failed to {$step}: ".$this->output($result->errorOutput(), $result->output()));
    }

    private function runRequiredWithInput(string $command, string $input, string $step): void
    {
        $result = Process::timeout(60)->input($input)->run($command);

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Failed to {$step}: ".$this->output($result->errorOutput(), $result->output()));
    }

    private function runShellScript(string $script, string $step): void
    {
        $result = Process::timeout(180)->input($script)->run('bash -s');

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Failed to {$step}: ".$this->output($result->errorOutput(), $result->output()));
    }

    private function output(string $errorOutput, string $output): string
    {
        $message = trim($errorOutput.' '.$output);

        return $message !== '' ? $message : 'unknown error';
    }

    private function bootstrapConfigRoot(string $configRoot): void
    {
        File::ensureDirectoryExists($configRoot, 0700);
        File::ensureDirectoryExists("{$configRoot}/certs", 0700);

        $database = "{$configRoot}/gateway.sqlite";

        if (! File::exists($database)) {
            File::put($database, '');
        }

        $envPath = "{$configRoot}/.env";
        $contents = File::exists($envPath) ? File::get($envPath) : '';

        foreach ([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_BUSY_TIMEOUT' => '5000',
            'DB_JOURNAL_MODE' => 'wal',
            'DB_SYNCHRONOUS' => 'NORMAL',
            'ORBIT_CONFIG_ROOT' => $configRoot,
        ] as $key => $value) {
            $contents = $this->setEnvValue($contents, $key, $value);
        }

        File::put($envPath, $contents);
    }

    private function setEnvValue(string $contents, string $key, string $value): string
    {
        $line = "{$key}={$value}";

        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents) === 1) {
            return preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents) ?? $contents;
        }

        $contents = rtrim($contents);

        return $contents === '' ? "{$line}\n" : "{$contents}\n{$line}\n";
    }

    private function configRoot(?string $configRoot): string
    {
        $configRoot ??= config('orbit.paths.config_root');

        if (! is_string($configRoot) || trim($configRoot) === '') {
            throw new RuntimeException('Gateway Swarm config root is not configured.');
        }

        return rtrim($configRoot, '/');
    }
}
