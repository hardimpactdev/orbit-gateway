<?php

declare(strict_types=1);

use App\Services\Gateway\GatewayApiContainerInstaller;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Runtime\OrbitGatewayContainerRenderer;
use App\Tools\CaddyTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

function gatewayApiInstallerPathIsCaddyVisible(string $path, OrbitCaddyContainer $container): bool
{
    foreach ($container->mounts() as $mount) {
        $target = $mount['target'];

        if ($path === $target) {
            return true;
        }

        if (str_starts_with($path, rtrim($target, '/').'/')) {
            return true;
        }
    }

    return false;
}

describe('GatewayApiContainerInstaller', function (): void {
    beforeEach(function (): void {
        $this->tempStorage = sys_get_temp_dir().'/orbit-gateway-api-container-test-'.uniqid();
        mkdir($this->tempStorage.'/app/orbit', 0777, true);
        app()->useStoragePath($this->tempStorage);
        $this->tempConfigRoot = "{$this->tempStorage}/config";
        File::ensureDirectoryExists($this->tempConfigRoot);
        config()->set('orbit.paths.config_root', $this->tempConfigRoot);
        $this->databasePath = $this->tempStorage.'/gateway-test.sqlite';

        touch($this->databasePath);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $this->databasePath);

        DB::purge('sqlite');
        Schema::connection('sqlite')->dropAllTables();

        Schema::connection('sqlite')->create('nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status');
        });

        Schema::connection('sqlite')->create('node_role', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('node_id');
            $table->string('role');
            $table->string('status');
        });

        DB::connection('sqlite')->table('nodes')->insert([
            'id' => 1,
            'name' => 'gateway-1',
            'status' => 'active',
        ]);

        DB::connection('sqlite')->table('node_role')->insert([
            'node_id' => 1,
            'role' => 'gateway',
            'status' => 'active',
        ]);
    });

    afterEach(function (): void {
        if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
            File::deleteDirectory($this->tempStorage);
        }
    });

    it('issues a leaf certificate and routes the gateway API through orbit-caddy to orbit-gateway', function (): void {
        $writtenGlobalCaddyfile = null;
        $writtenGatewayApiCaddyfile = null;
        $caDir = $this->tempConfigRoot.'/ca';
        $certsDir = $this->tempConfigRoot.'/certs';

        File::ensureDirectoryExists($caDir);
        File::ensureDirectoryExists($certsDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

        Process::fake(function ($process) use (&$writtenGlobalCaddyfile, &$writtenGatewayApiCaddyfile
        ) {
            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'tee /etc/caddy/Caddyfile')) {
                $writtenGlobalCaddyfile = (string) $process->input;
            }

            if (str_contains($process->command, 'tee /etc/caddy/orbit/orbit-api.caddy')) {
                $writtenGatewayApiCaddyfile = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(GatewayApiContainerInstaller::class)->install('10.6.0.2', orbitPath: '/home/orbit/orbit');

        expect($writtenGlobalCaddyfile)->toContain('(security_headers)')
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/orbit/*.caddy')
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/sites/*.caddy')
            ->and($writtenGatewayApiCaddyfile)->toContain(':80 {')
            ->and($writtenGatewayApiCaddyfile)->toContain(':443 {')
            ->and($writtenGatewayApiCaddyfile)->not->toContain('https://10.6.0.2:443')
            ->and($writtenGatewayApiCaddyfile)->not->toContain('bind 10.6.0.2')
            ->and($writtenGatewayApiCaddyfile)->toContain('tls /etc/orbit/certs/10.6.0.2.crt /etc/orbit/certs/10.6.0.2.key')
            ->and($writtenGatewayApiCaddyfile)->toContain('reverse_proxy http://orbit-gateway:8080')
            ->and($writtenGatewayApiCaddyfile)->toContain('flush_interval -1')
            ->and($writtenGatewayApiCaddyfile)->toContain('header_up X-Forwarded-Proto http')
            ->and($writtenGatewayApiCaddyfile)->toContain('header_up X-Forwarded-Proto https')
            ->and($writtenGatewayApiCaddyfile)->toContain('request_header -X-Orbit-WireGuard-Ip')
            ->and(substr_count($writtenGatewayApiCaddyfile, 'header_up X-Orbit-WireGuard-Ip {remote_host}'))->toBe(2)
            ->and($writtenGatewayApiCaddyfile)->not->toContain('php_fastcgi')
            ->and($writtenGatewayApiCaddyfile)->not->toContain('php-fpm')
            ->and($writtenGatewayApiCaddyfile)->not->toContain('orbit-api.sock');

        Process::assertRan('sudo install -d -m 0755 /etc/orbit/certs');
        Process::assertRan('sudo install -m 0644 '.escapeshellarg("{$certsDir}/10.6.0.2.crt")." '/etc/orbit/certs/10.6.0.2.crt'");
        Process::assertRan('sudo install -m 0644 '.escapeshellarg("{$certsDir}/10.6.0.2.key")." '/etc/orbit/certs/10.6.0.2.key'");
    });

    it('preserves real-time streaming through the containerized gateway api with flush_interval disabled', function (): void {
        $writtenGatewayApiCaddyfile = null;
        $caDir = $this->tempConfigRoot.'/ca';
        $certsDir = $this->tempConfigRoot.'/certs';

        File::ensureDirectoryExists($caDir);
        File::ensureDirectoryExists($certsDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

        Process::fake(function ($process) use (&$writtenGatewayApiCaddyfile) {
            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'tee /etc/caddy/orbit/orbit-api.caddy')) {
                $writtenGatewayApiCaddyfile = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(GatewayApiContainerInstaller::class)->install('10.6.0.2', orbitPath: '/home/orbit/orbit');

        expect($writtenGatewayApiCaddyfile)
            ->toContain('flush_interval -1')
            ->and($writtenGatewayApiCaddyfile)->toContain('reverse_proxy http://orbit-gateway:8080');
    });

    it('ensures the orbit-gateway container before writing the gateway API Caddy config', function (): void {
        $caDir = $this->tempConfigRoot.'/ca';
        $certsDir = $this->tempConfigRoot.'/certs';

        File::ensureDirectoryExists($caDir);
        File::ensureDirectoryExists($certsDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

        $builder = new DockerCommandBuilder;
        $renderer = new OrbitGatewayContainerRenderer(new OrbitContainerNames);
        $gatewayContainer = $renderer->render(
            orbitCheckoutPath: '/home/orbit/orbit',
            gatewayConfigRoot: rtrim((string) getenv('HOME'), '/').'/.config/orbit',
        );

        $invocations = [];

        Process::fake(function ($process) use ($builder, $gatewayContainer, &$invocations) {
            $invocations[] = $process->command;

            if ($process->command === $builder->networkInspect($gatewayContainer->network())) {
                return Process::result(exitCode: 1);
            }

            if ($process->command === $builder->containerInspect($gatewayContainer->name())) {
                return Process::result(exitCode: 1);
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(GatewayApiContainerInstaller::class)->install('10.6.0.2', orbitPath: '/home/orbit/orbit');

        $gatewayCreateIndex = null;
        $caddyConfigWriteIndex = null;
        $caddyReloadIndex = null;

        foreach ($invocations as $i => $command) {
            if ($command === $builder->runDetached($gatewayContainer)) {
                $gatewayCreateIndex = $i;
            }
            if (str_contains($command, 'tee /etc/caddy/orbit/orbit-api.caddy')) {
                $caddyConfigWriteIndex = $i;
            }
            if ($command === CaddyTool::reloadCommand('orbit-caddy')) {
                $caddyReloadIndex = $i;
            }
        }

        expect($gatewayCreateIndex)->not->toBeNull('orbit-gateway container must be created')
            ->and($caddyConfigWriteIndex)->not->toBeNull('gateway API Caddy config must be written')
            ->and($caddyReloadIndex)->not->toBeNull('orbit-caddy must be reloaded')
            ->and($gatewayCreateIndex)->toBeLessThan($caddyConfigWriteIndex, 'orbit-gateway must be created before the Caddy config is written')
            ->and($caddyConfigWriteIndex)->toBeLessThan($caddyReloadIndex, 'Caddy config must be written before orbit-caddy is reloaded');
    });

    it('reloads the orbit-caddy container and never installs or restarts host PHP-FPM or host Caddy', function (): void {
        $caDir = $this->tempConfigRoot.'/ca';
        $certsDir = $this->tempConfigRoot.'/certs';

        File::ensureDirectoryExists($caDir);
        File::ensureDirectoryExists($certsDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

        Process::fake(function ($process) {
            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(GatewayApiContainerInstaller::class)->install('10.6.0.2', orbitPath: '/home/orbit/orbit');

        Process::assertRan('sudo install -d -m 0755 /etc/caddy /etc/caddy/orbit /etc/caddy/sites');
        Process::assertRan('sudo tee /etc/caddy/orbit/orbit-api.caddy > /dev/null');
        Process::assertRan(CaddyTool::reloadCommand('orbit-caddy'));

        Process::assertNotRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, 'systemctl'));
        Process::assertNotRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, 'php-fpm'));
        Process::assertNotRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, 'php8.5-fpm'));
        Process::assertNotRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, 'tee /etc/php/'));
        Process::assertNotRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, 'usermod -aG orbit caddy'));
    });

    it('copies issued gateway API TLS material to the caddy-readable /etc/orbit bind mount', function (): void {
        $writtenGatewayApiCaddyfile = null;

        $tempContainerRoot = sys_get_temp_dir().'/orbit-gateway-api-caddy-certs-'.uniqid();
        mkdir($tempContainerRoot, 0777, true);
        app()->useStoragePath($tempContainerRoot);

        $caDir = $this->tempConfigRoot.'/ca';
        $certsDir = $this->tempConfigRoot.'/certs';
        File::ensureDirectoryExists($caDir);
        File::ensureDirectoryExists($certsDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

        Process::fake(function ($process) use (&$writtenGatewayApiCaddyfile) {
            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'tee /etc/caddy/orbit/orbit-api.caddy')) {
                $writtenGatewayApiCaddyfile = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        try {
            app(GatewayApiContainerInstaller::class)->install('10.6.0.2', orbitPath: '/home/orbit/orbit');
        } finally {
            if (is_dir($tempContainerRoot)) {
                File::deleteDirectory($tempContainerRoot);
            }
        }

        $caddyCertPath = '/etc/orbit/certs/10.6.0.2.crt';
        $caddyKeyPath = '/etc/orbit/certs/10.6.0.2.key';

        $caddyContainer = OrbitCaddyContainer::forPrivateNode('10.6.0.2');

        expect($writtenGatewayApiCaddyfile)->toContain("tls {$caddyCertPath} {$caddyKeyPath}")
            ->and($writtenGatewayApiCaddyfile)->not->toContain($certsDir)
            ->and(gatewayApiInstallerPathIsCaddyVisible($caddyCertPath, $caddyContainer))->toBeTrue('gateway API cert path must fall under an orbit-caddy bind mount')
            ->and(gatewayApiInstallerPathIsCaddyVisible($caddyKeyPath, $caddyContainer))->toBeTrue('gateway API key path must fall under an orbit-caddy bind mount');

        Process::assertRan('sudo install -d -m 0755 /etc/orbit/certs');
        Process::assertRan('sudo install -m 0644 '.escapeshellarg("{$certsDir}/10.6.0.2.crt").' '.escapeshellarg($caddyCertPath));
        Process::assertRan('sudo install -m 0644 '.escapeshellarg("{$certsDir}/10.6.0.2.key").' '.escapeshellarg($caddyKeyPath));
    });

    it('preserves an existing global Caddyfile and only ensures managed imports and snippets', function (): void {
        $readExistingCaddyfileCommand = "sudo test -f '/etc/caddy/Caddyfile' && sudo cat '/etc/caddy/Caddyfile' || true";
        $writtenGlobalCaddyfile = null;
        $writtenGatewayApiCaddyfile = null;

        $caDir = $this->tempConfigRoot.'/ca';
        $certsDir = $this->tempConfigRoot.'/certs';

        File::ensureDirectoryExists($caDir);
        File::ensureDirectoryExists($certsDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

        Process::fake(function ($process) use ($readExistingCaddyfileCommand, &$writtenGlobalCaddyfile, &$writtenGatewayApiCaddyfile) {
            if ($process->command === $readExistingCaddyfileCommand) {
                return Process::result(<<<'CADDY'
{
    admin off
}

import /etc/caddy/sites/*.caddy
import /etc/caddy/orbit/orbit-web.caddy
import /etc/caddy/orbit/tld-proxies.caddy
CADDY);
            }

            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'tee /etc/caddy/Caddyfile')) {
                $writtenGlobalCaddyfile = (string) $process->input;
            }

            if (str_contains($process->command, 'tee /etc/caddy/orbit/orbit-api.caddy')) {
                $writtenGatewayApiCaddyfile = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(GatewayApiContainerInstaller::class)->install('10.6.0.2', orbitPath: '/home/orbit/orbit');

        expect($writtenGlobalCaddyfile)->toContain('admin localhost:2019')
            ->and($writtenGlobalCaddyfile)->toContain('local_certs')
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/sites/*.caddy')
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/orbit/orbit-web.caddy')
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/orbit/tld-proxies.caddy')
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/orbit/*.caddy')
            ->and(substr_count($writtenGlobalCaddyfile, 'import /etc/caddy/sites/*.caddy'))->toBe(1)
            ->and(substr_count($writtenGlobalCaddyfile, 'import /etc/caddy/orbit/*.caddy'))->toBe(1)
            ->and(substr_count($writtenGlobalCaddyfile, '{'))->toBe(substr_count($writtenGlobalCaddyfile, '}'))
            ->and(substr_count($writtenGlobalCaddyfile, 'admin localhost:2019'))->toBe(1)
            ->and($writtenGlobalCaddyfile)->not->toContain('admin off')
            ->and($writtenGatewayApiCaddyfile)->toContain(':80 {')
            ->and($writtenGatewayApiCaddyfile)->toContain(':443 {')
            ->and($writtenGatewayApiCaddyfile)->not->toContain('https://10.6.0.2:443');
    });
});
