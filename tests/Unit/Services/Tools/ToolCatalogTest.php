<?php

declare(strict_types=1);

use App\Contracts\ToolDefinition;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\Processes\ProcessServiceDefinitionRegistry;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Tools\ToolCatalog;
use App\Tools\CaddyTool;
use App\Tools\GhTool;
use App\Tools\HermesTool;
use App\Tools\OpenCodeServerTool;
use App\Tools\PolyscopeServerTool;
use App\Tools\SeaweedfsTool;
use Tests\TestCase;

uses(TestCase::class);

describe('tool catalog definitions', function (): void {
    it('resolves supported tools through dedicated definitions', function (): void {
        $catalog = app(ToolCatalog::class);

        expect($catalog->definitions())
            ->toHaveCount(count($catalog->names()))
            ->each->toBeInstanceOf(ToolDefinition::class);

        expect($catalog->definition('polyscope-server'))
            ->toBeInstanceOf(PolyscopeServerTool::class);
    });

    it('catalogs gh as an app-role runtime tool, not a fleet-wide always tool', function (): void {
        $catalog = app(ToolCatalog::class);

        expect($catalog->definition('gh'))->toBeInstanceOf(GhTool::class)
            ->and($catalog->category('gh'))->toBe('runtime')
            ->and($catalog->hasCapability('gh', 'install'))->toBeTrue()
            ->and($catalog->hasCapability('gh', 'update'))->toBeTrue()
            ->and($catalog->hasCapability('gh', 'safe-adopt'))->toBeTrue();
    });

    it('does not catalog runnable database and cache services as tools', function (string $tool): void {
        $catalog = app(ToolCatalog::class);

        expect($catalog->supports($tool))->toBeFalse()
            ->and($catalog->definition($tool))->toBeNull()
            ->and($catalog->installScript($tool))->toBeNull();
    })->with([
        'clickhouse',
        'mysql',
        'plausible',
        'postgres',
        'redis',
        'supervisor',
    ]);

    it('catalogs runnable services as process service definitions instead', function (): void {
        $registry = app(ProcessServiceDefinitionRegistry::class);

        expect($registry->supports('clickhouse'))->toBeTrue()
            ->and($registry->supports('mysql'))->toBeTrue()
            ->and($registry->supports('plausible'))->toBeTrue()
            ->and($registry->supports('postgres'))->toBeTrue()
            ->and($registry->supports('redis'))->toBeTrue();
    });

    it('catalogs node-exporter as a host binary tool with process-owned lifecycle', function (): void {
        $catalog = app(ToolCatalog::class);
        $installScript = $catalog->installScript('node-exporter');
        $metadata = $catalog->probeMetadata('node-exporter');

        expect($catalog->supports('node-exporter'))->toBeTrue()
            ->and($catalog->category('node-exporter'))->toBe('observability')
            ->and($catalog->hasCapability('node-exporter', 'install'))->toBeTrue()
            ->and($catalog->hasCapability('node-exporter', 'update'))->toBeTrue()
            ->and($installScript)->toContain('node_exporter-1.11.1.linux-${node_exporter_arch}.tar.gz')
            ->and($installScript)->toContain('/usr/local/bin/node_exporter')
            ->and($metadata)->toMatchArray([
                'binary' => '/usr/local/bin/node_exporter',
                'version_command' => '/usr/local/bin/node_exporter --version 2>/dev/null | head -n 1',
            ])
            ->and($metadata)->not->toHaveKey('service')
            ->and($catalog->logCommand('node-exporter', 50))->toBeNull();
    });

    it('catalogs agent IDE servers as installed capabilities with process-owned lifecycle', function (string $tool, string $binary, string $definition): void {
        $catalog = app(ToolCatalog::class);
        $metadata = $catalog->probeMetadata($tool);
        $repairCommands = is_array($metadata['repair_commands'] ?? null)
            ? $metadata['repair_commands']
            : [];

        expect($catalog->definition($tool))->toBeInstanceOf($definition)
            ->and($metadata)->toMatchArray([
                'binary' => $binary,
            ])
            ->and($metadata)->not->toHaveKey('supervisor_program')
            ->and($metadata)->not->toHaveKey('supervisor_log')
            ->and($repairCommands)->toBe([])
            ->and($catalog->logCommand($tool, 50))->toBeNull()
            ->and($catalog->logCommand($tool, 50, follow: true))->toBeNull();

        foreach ([
            $catalog->installScript($tool),
            $catalog->removeScript($tool),
            $catalog->reconfigureScript($tool),
            $catalog->updateScript($tool),
        ] as $script) {
            expect((string) $script)
                ->not->toContain('supervisorctl')
                ->not->toContain('/etc/supervisor')
                ->not->toContain('systemctl')
                ->not->toContain('loginctl')
                ->not->toContain('.config/systemd/user');
        }
    })->with([
        'opencode server' => ['opencode-server', 'opencode', OpenCodeServerTool::class],
        'polyscope server' => ['polyscope-server', 'polyscope-server', PolyscopeServerTool::class],
    ]);

    it('describes caddy as the orbit-caddy Docker container instead of a host Caddy service', function (): void {
        $catalog = app(ToolCatalog::class);
        $metadata = $catalog->probeMetadata('caddy');
        $repairCommands = is_array($metadata['repair_commands'] ?? null)
            ? $metadata['repair_commands']
            : [];

        expect($catalog->definition('caddy'))->toBeInstanceOf(CaddyTool::class)
            ->and($metadata)->toMatchArray([
                'binary' => 'docker',
                'container' => 'orbit-caddy',
                'image' => 'caddy:2-alpine',
            ])
            ->and($repairCommands['lifecycle_running'] ?? null)->toContain('docker start')
            ->and($repairCommands['lifecycle_running'] ?? null)->toContain('orbit-caddy')
            ->and($repairCommands['lifecycle_reloaded'] ?? null)->toContain('docker exec')
            ->and($repairCommands['lifecycle_reloaded'] ?? null)->toContain('caddy reload')
            ->and($repairCommands['lifecycle_reloaded'] ?? null)->toContain('orbit-caddy')
            ->and($catalog->logCommand('caddy', 50))->toContain('docker logs')
            ->and($catalog->logCommand('caddy', 50))->toContain('orbit-caddy');

        foreach ([$catalog->reconfigureScript('caddy'), $catalog->updateScript('caddy'), ...array_values($repairCommands)] as $script) {
            expect((string) $script)
                ->not->toContain('systemctl')
                ->not->toContain('journalctl')
                ->not->toContain('apt-get')
                ->not->toContain('sudo caddy reload');
        }
    });

    it('uses caddy reload through the container-local admin endpoint for config changes', function (): void {
        $reloadScript = app(ToolCatalog::class)->reconfigureScript('caddy');

        expect((new CaddyGlobalConfig)->fresh())
            ->toContain('admin localhost:2019')
            ->and($reloadScript)->toBe(CaddyTool::reloadCommand('orbit-caddy'))
            ->and($reloadScript)->toContain('caddy reload')
            ->and($reloadScript)->toContain('--address localhost:2019')
            ->and($reloadScript)->not->toContain('docker restart');
    });

    it('converges drifted orbit-caddy containers by recreating them from the declared spec', function (): void {
        $script = app(ToolCatalog::class)->updateScript('caddy');

        expect($script)
            ->toBeString()
            ->toContain('docker network inspect')
            ->toContain('docker network create')
            ->toContain('orbit-network')
            ->toContain('/etc/caddy/Caddyfile')
            ->toContain('/etc/caddy/orbit')
            ->toContain('/etc/caddy/sites')
            ->toContain('--mount '.escapeshellarg('type=bind,source=/etc/caddy/Caddyfile,target=/etc/caddy/Caddyfile,readonly'))
            ->toContain('--mount '.escapeshellarg('type=bind,source=/etc/caddy/orbit,target=/etc/caddy/orbit,readonly'))
            ->toContain('--mount '.escapeshellarg('type=bind,source=/etc/caddy/sites,target=/etc/caddy/sites,readonly'))
            ->toContain('--mount '.escapeshellarg('type=bind,source=/etc/orbit,target=/etc/orbit,readonly'))
            ->toContain('--mount '.escapeshellarg('type=bind,source=/home,target=/home,readonly'))
            ->toContain('--mount '.escapeshellarg('type=bind,source=/run/php,target=/run/php'))
            ->toContain('--mount '.escapeshellarg('type=bind,source=/var/lib/orbit/caddy/data,target=/data/caddy'))
            ->toContain('--mount '.escapeshellarg('type=bind,source=/var/lib/orbit/caddy/config,target=/config/caddy'))
            ->not->toContain('/var/lib/caddy/.local/share/caddy')
            ->not->toContain('/var/lib/caddy/.config/caddy')
            ->toContain('--add-host '.escapeshellarg('host.docker.internal:host-gateway'))
            ->toContain('orbit.caddy.spec_hash')
            ->toContain('actual_hash=')
            ->toContain('expected_hash=')
            ->toContain('if [ "$actual_hash" != "$expected_hash" ]; then')
            ->toContain('docker rm -f')
            ->toContain('docker run -d')
            ->toContain('--pull '.escapeshellarg('never'))
            ->toContain('docker start')
            ->not->toContain('exit 0')
            ->not->toContain('then docker restart');
    });

    it('prepares every orbit-caddy bind mount source on the host before docker run', function (): void {
        $script = app(ToolCatalog::class)->updateScript('caddy');

        $directories = (OrbitCaddyContainer::default())->hostMountDirectories();

        expect($directories)
            ->toContain('/etc/caddy', '/etc/caddy/orbit', '/etc/caddy/sites', '/etc/orbit', '/home', '/run/php', '/var/lib/orbit/caddy/data', '/var/lib/orbit/caddy/config')
            ->not->toContain('/var/lib/caddy/.local/share/caddy', '/var/lib/caddy/.config/caddy');

        $installLine = collect(explode("\n", $script))
            ->first(fn (string $line): bool => str_starts_with(trim($line), 'sudo install -d -m 0755'));

        expect($installLine)->not->toBeNull();

        foreach ($directories as $directory) {
            expect($installLine)->toContain(escapeshellarg($directory));
        }
    });

    it('does not docker-pull the Caddy image inline during reconcile and uses --pull never', function (): void {
        $script = app(ToolCatalog::class)->updateScript('caddy');

        $lines = explode("\n", $script);
        $pullLineIndex = null;
        $dockerRunLineIndex = null;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($pullLineIndex === null && str_starts_with($trimmed, 'docker pull')) {
                $pullLineIndex = $index;
            }

            if ($dockerRunLineIndex === null && str_contains($trimmed, 'docker run -d')) {
                $dockerRunLineIndex = $index;
            }
        }

        expect($pullLineIndex)->toBeNull()
            ->and($script)
            ->not->toContain('--pull '.escapeshellarg('missing'))
            ->not->toContain('--pull=missing')
            ->not->toContain('--pull '.escapeshellarg('always'))
            ->not->toContain('--pull=always')
            ->toContain('--pull '.escapeshellarg('never'))
            ->toContain('docker container inspect')
            ->toContain('docker run -d')
            ->and($dockerRunLineIndex)->not->toBeNull();
    });

    it('fails early with a clear diagnostic before docker run when the official Caddy image is missing locally', function (): void {
        $script = app(ToolCatalog::class)->updateScript('caddy');

        $lines = explode("\n", $script);
        $imageCheckLineIndex = null;
        $dockerRunLineIndex = null;

        foreach ($lines as $index => $line) {
            if ($imageCheckLineIndex === null && str_contains($line, 'docker image inspect') && str_contains($line, 'caddy:2-alpine')) {
                $imageCheckLineIndex = $index;
            }

            if ($dockerRunLineIndex === null && str_contains($line, 'docker run -d')) {
                $dockerRunLineIndex = $index;
            }
        }

        expect($imageCheckLineIndex)->not->toBeNull()
            ->and($dockerRunLineIndex)->not->toBeNull()
            ->and($imageCheckLineIndex)->toBeLessThan($dockerRunLineIndex)
            ->and($script)
            ->toContain('orbit-caddy: local Docker image caddy:2-alpine is missing')
            ->toContain('orbit:internal:build-gateway-images')
            ->toContain('docker pull caddy:2-alpine')
            ->toContain('exit 69');
    });

    it('catalogs seaweedfs as the s3 role storage tool with credentials capability and Docker-first runtime metadata', function (): void {
        $catalog = app(ToolCatalog::class);
        $metadata = $catalog->probeMetadata('seaweedfs');
        $repairCommands = is_array($metadata['repair_commands'] ?? null)
            ? $metadata['repair_commands']
            : [];

        expect($catalog->definition('seaweedfs'))->toBeInstanceOf(SeaweedfsTool::class)
            ->and($catalog->requiredNodeRole('seaweedfs'))->toBe('s3')
            ->and($catalog->category('seaweedfs'))->toBe('storage')
            ->and($catalog->hasCapability('seaweedfs', 'credentials'))->toBeTrue()
            ->and($catalog->hasCapability('seaweedfs', 'safe-fix'))->toBeTrue()
            ->and($catalog->hasCapability('seaweedfs', 'safe-adopt'))->toBeTrue()
            ->and($metadata)->toMatchArray([
                'binary' => 'docker',
                'container' => 'orbit-seaweedfs',
                'image' => 'chrislusf/seaweedfs:4.33',
            ])
            ->and($repairCommands['lifecycle_running'] ?? null)->toContain('docker start')
            ->and($repairCommands['lifecycle_running'] ?? null)->toContain('orbit-seaweedfs')
            ->and($repairCommands['lifecycle_stopped'] ?? null)->toContain('docker stop')
            ->and($repairCommands['lifecycle_stopped'] ?? null)->toContain('orbit-seaweedfs')
            ->and($repairCommands['lifecycle_restarted'] ?? null)->toContain('docker restart')
            ->and($repairCommands['lifecycle_restarted'] ?? null)->toContain('orbit-seaweedfs')
            ->and($catalog->logCommand('seaweedfs', 50))->toContain('docker logs')
            ->and($catalog->logCommand('seaweedfs', 50))->toContain('orbit-seaweedfs');
    });

    it('probes Hermes through the system wrapper that delegates to the agent user', function (): void {
        $metadata = app(ToolCatalog::class)->probeMetadata('hermes');

        expect(app(ToolCatalog::class)->definition('hermes'))->toBeInstanceOf(HermesTool::class)
            ->and($metadata)->toMatchArray([
                'binary' => '/usr/local/bin/hermes',
                'version_command' => '/usr/local/bin/hermes --version 2>/dev/null || true',
            ]);
    });
});
