<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Services\Ca\OrbitCaService;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Runtime\OrbitGatewayContainerManager;
use App\Services\Runtime\OrbitGatewayContainerRenderer;
use App\Tools\CaddyTool;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Docker-first gateway API service installer.
 *
 * The gateway API is the gateway `orbit-gateway` container, exposed through
 * the gateway `orbit-caddy` container. The installer issues the leaf
 * certificate, writes the gateway-API Caddy site that orbit-caddy serves on
 * the WireGuard address, and reloads orbit-caddy. It does not install or
 * restart host PHP, host PHP-FPM, or host Caddy.
 *
 * @see apps/docs/content/domains/2_gateway/README.md — "The gateway API service is the
 *     gateway `orbit-gateway` container, exposed on the Orbit network
 *     through the gateway `orbit-caddy` container."
 */
class GatewayApiContainerInstaller
{
    /**
     * HTTP port the gateway's `orbit-gateway` container listens on for the
     * typed Orbit API. `orbit-caddy` reverse-proxies HTTPS gateway traffic
     * to this internal HTTP port over the orbit-network bridge.
     */
    public const GatewayApiPort = 8080;

    public function __construct(
        private readonly OrbitCaService $caService,
        private readonly CaddyGlobalConfig $caddyGlobalConfig,
        private readonly CaddyTool $caddyTool = new CaddyTool,
        private readonly OrbitContainerNames $containerNames = new OrbitContainerNames,
        private readonly OrbitGatewayContainerRenderer $gatewayRenderer = new OrbitGatewayContainerRenderer(new OrbitContainerNames),
        private readonly OrbitGatewayContainerManager $gatewayManager = new OrbitGatewayContainerManager(new DockerCommandBuilder),
    ) {}

    public function install(string $wireguardAddress, string $phpVersion = '8.5', string $orbitPath = ''): void
    {
        if (filter_var($wireguardAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException("Invalid WireGuard API address: {$wireguardAddress}");
        }

        $leaf = $this->caService->issueLeaf($wireguardAddress);

        $this->ensureOrbitGatewayContainer($orbitPath);
        $this->ensureOrbitCaddyContainer($wireguardAddress);

        $caddyLeaf = $this->installCaddyReadableLeaf($leaf, $wireguardAddress);

        $this->runRequiredWithInput('sudo tee /etc/caddy/orbit/orbit-api.caddy > /dev/null', $this->gatewayApiCaddyfile(
            certPath: $caddyLeaf['cert'],
            keyPath: $caddyLeaf['key'],
        ), 'write Orbit API Caddy config');
        $this->runRequired(CaddyTool::reloadCommand($this->containerNames->caddy()), 'reload orbit-caddy container');
    }

    /**
     * @param  array{cert: string, key: string}  $leaf
     * @return array{cert: string, key: string}
     */
    private function installCaddyReadableLeaf(array $leaf, string $wireguardAddress): array
    {
        $caddyLeaf = [
            'cert' => "/etc/orbit/certs/{$wireguardAddress}.crt",
            'key' => "/etc/orbit/certs/{$wireguardAddress}.key",
        ];

        $this->runRequired('sudo install -d -m 0755 /etc/orbit/certs', 'prepare Orbit Caddy certificate directory');
        $this->runRequired(sprintf(
            'sudo install -m 0644 %s %s',
            escapeshellarg($leaf['cert']),
            escapeshellarg($caddyLeaf['cert']),
        ), 'install Orbit API certificate for orbit-caddy');
        $this->runRequired(sprintf(
            'sudo install -m 0644 %s %s',
            escapeshellarg($leaf['key']),
            escapeshellarg($caddyLeaf['key']),
        ), 'install Orbit API certificate key for orbit-caddy');

        return $caddyLeaf;
    }

    /**
     * Converge the gateway orbit-gateway container before orbit-caddy is
     * configured to route to it. The gateway container mounts the Orbit
     * checkout and the gateway database so the API can serve from inside
     * the container.
     */
    private function ensureOrbitGatewayContainer(string $orbitPath): void
    {
        $resolvedPath = $orbitPath !== '' ? $orbitPath : repo_path();

        $container = $this->gatewayRenderer->render(
            orbitCheckoutPath: $resolvedPath,
            gatewayConfigRoot: $this->resolveConfigRoot(),
        );

        $this->gatewayManager->apply($container);
    }

    private function resolveConfigRoot(): string
    {
        $configRoot = getenv('ORBIT_CONFIG_ROOT');

        if (! is_string($configRoot) || trim($configRoot) === '') {
            $home = getenv('HOME');

            if (! is_string($home) || trim($home) === '') {
                $home = '/home/orbit';
            }

            $configRoot = rtrim($home, '/').'/.config/orbit';
        }

        return rtrim($configRoot, '/');
    }

    /**
     * Converge the gateway orbit-caddy container from the role-appropriate
     * `OrbitCaddyContainer::forPrivateNode($wireguardAddress)` spec before
     * the gateway-API site is written. Without this, fresh gateway hosts
     * end up with no orbit-caddy container to restart and bootstrap fails
     * silently into a host caddy fallback.
     */
    private function ensureOrbitCaddyContainer(string $wireguardAddress): void
    {
        $container = OrbitCaddyContainer::forPrivateNode($wireguardAddress, $this->containerNames);

        $this->runRequired('sudo install -d -m 0755 /etc/caddy /etc/caddy/orbit /etc/caddy/sites', 'prepare Caddy config directories');
        $this->ensureGlobalCaddyfile();

        $script = $this->caddyTool->updateScript(['container' => $container->spec()]);

        $this->runShellScript($script, 'converge orbit-caddy container');
    }

    private function gatewayApiCaddyfile(
        string $certPath,
        string $keyPath,
    ): string {
        $gatewayAlias = $this->containerNames->gateway();
        $port = self::GatewayApiPort;

        return <<<CADDY
:80 {
    request_header -X-Forwarded-For
    request_header -X-Real-IP
    request_header -Forwarded
    request_header -X-Orbit-WireGuard-Ip

    reverse_proxy http://{$gatewayAlias}:{$port} {
        flush_interval -1
        header_up Host {host}
        header_up X-Forwarded-Proto http
        header_up X-Orbit-WireGuard-Ip {remote_host}
    }
}

:443 {
    tls {$certPath} {$keyPath}

    request_header -X-Forwarded-For
    request_header -X-Real-IP
    request_header -Forwarded
    request_header -X-Orbit-WireGuard-Ip

    reverse_proxy http://{$gatewayAlias}:{$port} {
        flush_interval -1
        header_up Host {host}
        header_up X-Forwarded-Proto https
        header_up X-Orbit-WireGuard-Ip {remote_host}
    }
}

CADDY;
    }

    private function ensureGlobalCaddyfile(): void
    {
        $contents = $this->readOptional('/etc/caddy/Caddyfile');
        $updated = $this->caddyGlobalConfig->ensure($contents);

        if ($updated === $contents) {
            return;
        }

        $this->runRequiredWithInput('sudo tee /etc/caddy/Caddyfile > /dev/null', $updated, 'write global Caddy config');
    }

    private function readOptional(string $path): string
    {
        $command = 'sudo test -f '.escapeshellarg($path).' && sudo cat '.escapeshellarg($path).' || true';
        $result = Process::timeout(30)->run($command);

        if ($result->successful()) {
            return $result->output();
        }

        throw new RuntimeException("Failed to read {$path}: ".$this->output($result->errorOutput(), $result->output()));
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
}
