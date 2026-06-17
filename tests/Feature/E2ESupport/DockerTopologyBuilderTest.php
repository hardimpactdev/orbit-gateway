<?php

declare(strict_types=1);

use App\E2E\Support\DockerTopologyBuilder;
use App\E2E\Support\DockerTopologyNetworkPlan;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ECurrentCheckout;
use App\E2E\Support\E2ETopologyKind;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('defines the Docker topology host PHP 8.5 CLI baseline without ad hoc helper CLIs', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('FROM ubuntu:24.04')
        ->toContain('LABEL org.orbit.e2e.source="prepared-checkout"')
        ->toContain('software-properties-common')
        ->toContain('add-apt-repository ppa:ondrej/php -y')
        ->toContain('apt-get purge -y --auto-remove software-properties-common')
        ->toContain('php8.5-cli')
        ->toContain('php8.5-mbstring')
        ->toContain('php8.5-curl')
        ->toContain('php8.5-sqlite3')
        ->toContain('php8.5-xml')
        ->toContain('iproute2')
        ->toContain('redis-server')
        ->toContain('redis-server --daemonize yes --bind 0.0.0.0 --protected-mode no')
        ->toContain('update-alternatives --set php /usr/bin/php8.5')
        ->toContain('php --version')
        ->toContain('PHP 8.5.')
        ->toContain('["pdo_sqlite", "openssl", "curl", "mbstring", "json", "xml"]')
        ->toContain('pdo_sqlite')
        ->toContain('openssl')
        ->toContain('curl')
        ->toContain('mbstring')
        ->toContain('json')
        ->toContain('socket_gid="$(stat -c %g /var/run/docker.sock')
        ->toContain('groupmod -g "$socket_gid" docker')
        ->toContain('usermod -aG "$socket_group" orbit')
        ->not->toContain('COPY . /opt/orbit')
        ->not->toContain('COPY . /opt/orbit-source')
        ->not->toContain('/opt/orbit-source')
        ->not->toContain('cp -a /opt/orbit-source');

    expect(preg_match('/(?:^|\s)python3(?:\s|\\\\|$)/m', $dockerfile))->toBe(0)
        ->and(preg_match('/(?:^|\s)sqlite3(?:\s|\\\\|$)/m', $dockerfile))->toBe(0);
});

it('starts Docker build topology client nodes with the host Docker socket and no gateway sibling', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::Operator);

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain('--group-add "$(stat -c %g /var/run/docker.sock 2>/dev/null || stat -f %g /var/run/docker.sock)"')
        ->toContain("--volume '/var/run/docker.sock:/var/run/docker.sock'")
        ->toContain("--mount 'type=volume,src=orbit-e2e-prepared-build-operator-operator-etc-caddy,dst=/etc/caddy'")
        ->toContain("--mount 'type=volume,src=orbit-e2e-prepared-build-operator-operator-etc-orbit,dst=/etc/orbit'")
        ->toContain("--mount 'type=volume,src=orbit-e2e-prepared-build-operator-operator-opt-orbit,dst=/opt/orbit'")
        ->toContain("--env 'ORBIT_E2E_DOCKER_NETWORK=orbit-e2e-prepared-build-operator'")
        ->toContain("--env 'ORBIT_NODE_CONTAINER=orbit-e2e-prepared-build-operator-operator'")
        ->toContain("--env 'ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit'")
        ->toContain('ip addr add')
        ->toContain('10.6.0.3/24')
        ->toContain("--mount 'type=volume,src=orbit-e2e-composer-cache-")
        ->toContain('dst=/tmp/orbit-composer-cache')
        ->toContain("docker run -d --name 'orbit-e2e-prepared-build-operator-operator-composer'")
        ->toContain("'composer:2' tail -f /dev/null")
        ->toContain("--env 'ORBIT_SOURCE_PATH=/home/orbit/orbit'")
        ->toContain("--env 'COMPOSER_CACHE_DIR=/tmp/orbit-composer-cache'")
        ->toContain("--env 'COMPOSER_HOME=/tmp/orbit-composer-home'")
        ->toContain("--env 'COMPOSER_ALLOW_SUPERUSER=1'")
        ->toContain("cd '\\''/home/orbit/orbit/apps/gateway'\\''")
        ->toContain('/home/orbit/orbit/apps/cli')
        ->toContain('mkdir -p')
        ->toContain('/tmp/orbit-composer-home/config.json')
        ->toContain('cache-dir')
        ->toContain('github-protocols')
        ->toContain("git config --global --add safe.directory '\\''*'\\''")
        ->toContain('/tmp/orbit-composer-cache')
        ->toContain('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress')
        ->not->toContain("--env 'ORBIT_GATEWAY_CONTAINER=orbit-e2e-prepared-build-operator-operator-orbit-gateway'")
        ->not->toContain("docker run -d --restart unless-stopped --name 'orbit-e2e-prepared-build-operator-operator-orbit-gateway'");

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress')
        && $process->timeout === 1200);
});

it('can bind a build-host Composer cache into Docker topology containers', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    withE2EEnvironment(['ORBIT_E2E_DOCKER_COMPOSER_CACHE', 'ORBIT_E2E_DOCKER_COMPOSER_CACHE_READ_ONLY'], [
        'ORBIT_E2E_DOCKER_COMPOSER_CACHE' => '/home/build/.cache/composer',
        'ORBIT_E2E_DOCKER_COMPOSER_CACHE_READ_ONLY' => '1',
    ], function (): void {
        (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
            ->build(E2ETopologyKind::Operator);
    });

    expect(implode("\n", $commands))
        ->toContain("--mount 'type=bind,src=/home/build/.cache/composer,dst=/tmp/orbit-composer-cache'")
        ->toContain('cache-read-only')
        ->not->toContain('type=volume,src=orbit-e2e-composer-cache-');
});

it('uses the same lockfile keyed Composer cache volume across topology builds', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $builder = new DockerTopologyBuilder(E2EConfig::fromEnvironment());

    $builder->build(E2ETopologyKind::Operator);
    $builder->build(E2ETopologyKind::OperatorGateway);

    $mounts = collect($commands)
        ->filter(fn (string $command): bool => str_contains($command, 'type=volume,src=orbit-e2e-composer-cache-'))
        ->map(function (string $command): string {
            preg_match('/type=volume,src=(orbit-e2e-composer-cache-[a-f0-9]{16}),dst=/', $command, $matches);

            return $matches[1] ?? '';
        })
        ->filter()
        ->unique()
        ->values();

    expect($mounts)->toHaveCount(1)
        ->and($mounts[0])->toStartWith('orbit-e2e-composer-cache-');
});

it('retries docker build network allocation outside the orbit WireGuard subnet when a pool overlaps', function (): void {
    $networkCreates = [];

    Process::fake(function ($process) use (&$networkCreates) {
        if (str_starts_with($process->command, 'docker network create --subnet ')) {
            $networkCreates[] = $process->command;

            if (count($networkCreates) === 1) {
                return Process::result(errorOutput: 'Pool overlaps with other one on this address space', exitCode: 1);
            }
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::Operator);

    $retryPlan = DockerTopologyNetworkPlan::fromEnvironment('orbit-e2e-prepared-build-operator', attempt: 1);

    expect($networkCreates)->toHaveCount(2)
        ->and($networkCreates[0])->toContain('10.90.')
        ->and($networkCreates[0])->toContain('/24')
        ->and($networkCreates[0])->not->toContain('10.6.')
        ->and($networkCreates[1])->not->toBe($networkCreates[0]);

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE')
        && str_contains($process->command, '--group-add "$(stat -c %g /var/run/docker.sock 2>/dev/null || stat -f %g /var/run/docker.sock)"')
        && str_contains($process->command, "--name 'orbit-e2e-prepared-build-operator-operator'")
        && str_contains($process->command, "--ip '{$retryPlan->ipForRole('operator')}'"));
});

it('syncs the current checkout into each Docker topology node before installing role dependencies', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent);

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain('COPYFILE_DISABLE=1 tar --null -czf')
        ->toContain('-T ')
        ->toContain("docker exec -i 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-operator' tar --warning=no-unknown-keyword -xzf - -C '/home/orbit/orbit' < '")
        ->toContain("docker exec -i 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-gateway' tar --warning=no-unknown-keyword -xzf - -C '/home/orbit/orbit' < '")
        ->toContain('orbit-current-')
        ->toContain('ln -sfn')
        ->toContain('/home/orbit/orbit/apps/cli/orbit')
        ->toContain('/home/orbit/orbit/apps/cli/orbit')
        ->toContain('/usr/local/bin/orbit')
        ->toContain('/home/orbit/orbit/apps/cli')
        ->toContain('/home/orbit/orbit/apps/cli')
        ->toContain('/home/orbit/.config/orbit/.env')
        ->toContain('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader');

    $operatorSync = array_search(collect($commands)->first(fn (string $command): bool => str_contains($command, "docker exec -i 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-operator'")
        && str_contains($command, "tar --warning=no-unknown-keyword -xzf - -C '/home/orbit/orbit'")
        && str_contains($command, 'orbit-current-')), $commands, strict: true);
    $operatorInstall = array_search(collect($commands)->first(fn (string $command): bool => str_contains($command, "docker exec --env 'ORBIT_SOURCE_PATH=/home/orbit/orbit'")
        && str_contains($command, "'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-operator-composer'")
        && str_contains($command, '/home/orbit/orbit/apps/cli')
        && str_contains($command, 'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader')), $commands, strict: true);
    $gatewaySync = array_search(collect($commands)->first(fn (string $command): bool => str_contains($command, "docker exec -i 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-gateway'")
        && str_contains($command, "tar --warning=no-unknown-keyword -xzf - -C '/home/orbit/orbit'")
        && str_contains($command, 'orbit-current-')), $commands, strict: true);
    $gatewayReuse = array_search(collect($commands)->first(fn (string $command): bool => str_contains($command, "docker exec 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-operator-composer'")
        && str_contains($command, "tar -C '/home/orbit/orbit' -cf - apps/gateway/vendor apps/cli/vendor")
        && str_contains($command, "docker exec -i 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-gateway-orbit-gateway'")), $commands, strict: true);

    expect($operatorSync)->toBeInt()
        ->and($operatorInstall)->toBeInt()
        ->and($operatorSync)->toBeLessThan($operatorInstall)
        ->and($gatewaySync)->toBeInt()
        ->and($gatewayReuse)->toBeInt()
        ->and($gatewaySync)->toBeLessThan($gatewayReuse);
});

it('installs monorepo dependencies once and reuses them across Docker topology roles', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent);

    $composerInstallCommands = collect($commands)
        ->filter(fn (string $command): bool => str_contains($command, 'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader'))
        ->values();
    $cliInstallCommands = $composerInstallCommands
        ->filter(fn (string $command): bool => str_contains($command, '/apps/cli'))
        ->values();
    $gatewayInstallCommands = $composerInstallCommands
        ->filter(fn (string $command): bool => str_contains($command, '/home/orbit/orbit/apps/gateway'))
        ->values();
    $reuseCommands = collect($commands)
        ->filter(fn (string $command): bool => str_contains($command, "tar -C '/home/orbit/orbit' -cf - apps/gateway/vendor apps/cli/vendor")
            && str_contains($command, 'rm -rf apps/gateway/vendor apps/cli/vendor && tar -xf -'))
        ->values();

    expect($composerInstallCommands)->toHaveCount(1)
        ->and($cliInstallCommands)->toHaveCount(1)
        ->and($cliInstallCommands[0])->toContain('/home/orbit/orbit/apps/cli')
        ->and($gatewayInstallCommands)->toHaveCount(1)
        ->and($gatewayInstallCommands[0])->toContain('/home/orbit/orbit/apps/gateway')
        ->and($reuseCommands)->toHaveCount(4)
        ->and($reuseCommands[0])->toContain("docker exec 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-operator-composer'")
        ->and($reuseCommands[0])->toContain("docker exec -i 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-gateway-orbit-gateway'")
        ->and($reuseCommands[1])->toContain("docker exec 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-operator-composer'")
        ->and($reuseCommands[1])->toContain("docker exec -i 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-dev-composer'")
        ->and($reuseCommands[2])->toContain("docker exec 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-operator-composer'")
        ->and($reuseCommands[2])->toContain("docker exec -i 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-prod-composer'")
        ->and($reuseCommands[3])->toContain("docker exec 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-operator-composer'")
        ->and($reuseCommands[3])->toContain("docker exec -i 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-agent-composer'");

    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'dev-composer')
        && str_contains($process->command, 'composer install'));

    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'gateway-orbit-gateway')
        && str_contains($process->command, 'composer install'));
    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'prod-orbit-gateway')
        && str_contains($process->command, 'composer install'));
    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'agent-orbit-gateway')
        && str_contains($process->command, 'composer install'));
});

it('builds Docker checkout sync archives without gitignored local secrets', function (): void {
    $secretPath = base_path('storage/app/orbit/t384-topology-secret.key');
    $archive = null;

    if (! is_dir(dirname($secretPath))) {
        mkdir(dirname($secretPath), recursive: true);
    }
    file_put_contents($secretPath, 'secret');
    Process::preventStrayProcesses(false);

    try {
        $archive = E2ECurrentCheckout::buildArchive();
        $entries = [];
        exec(sprintf('tar -tzf %s', escapeshellarg($archive)), $entries, $exitCode);

        expect($exitCode)->toBe(0)
            ->and($entries)->toContain('composer.json')
            ->and($entries)->not->toContain('apps/gateway/storage/app/orbit/t384-topology-secret.key')
            ->and($entries)->not->toContain('./apps/gateway/storage/app/orbit/t384-topology-secret.key');
    } finally {
        Process::preventStrayProcesses();

        if (is_string($archive) && is_file($archive)) {
            @unlink($archive);
        }

        @unlink($secretPath);
    }
});

it('fails clearly when the orbit gateway sibling image is missing during docker topology preparation', function (): void {
    Process::fake(function ($process) {
        if ($process->command === "docker image inspect 'orbit-e2e-topology-runtime:prepared-current' >/dev/null") {
            return Process::result();
        }

        if ($process->command === "docker image inspect 'orbit-gateway:prepared-current' >/dev/null") {
            return Process::result(exitCode: 1);
        }

        return Process::result();
    });

    expect(fn () => (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGateway))
        ->toThrow(RuntimeException::class, 'Docker orbit-gateway sibling image is missing');
});

it('builds Docker topology state through the host orbit launcher', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGateway);

    $networkPlan = DockerTopologyNetworkPlan::fromEnvironment('orbit-e2e-prepared-build-operator_gateway');
    $gatewayIp = $networkPlan->ipForRole('gateway');
    $setup = implode("\n", $commands);
    $operatorComposer = strpos($setup, "docker exec --env 'ORBIT_SOURCE_PATH=/home/orbit/orbit' --env 'COMPOSER_CACHE_DIR=/tmp/orbit-composer-cache' --env 'COMPOSER_HOME=/tmp/orbit-composer-home' --env 'COMPOSER_PROCESS_TIMEOUT=1200' --env 'COMPOSER_ALLOW_SUPERUSER=1' --workdir '/home/orbit/orbit' 'orbit-e2e-prepared-build-operator_gateway-operator-composer'");
    $operatorMigrate = strpos($setup, "docker exec --user 'orbit' 'orbit-e2e-prepared-build-operator_gateway-operator' sh -lc 'cd /home/orbit/orbit && ORBIT_CONFIG_ROOT='\\''/home/orbit/.config/orbit'\\'' DB_CONNECTION=sqlite DB_DATABASE='\\''/home/orbit/.config/orbit/gateway.sqlite'\\'' SESSION_DRIVER=file php apps/gateway/artisan migrate --force --no-interaction --ansi'");
    $gatewayMigrate = strpos($setup, "docker exec --user 'orbit' 'orbit-e2e-prepared-build-operator_gateway-gateway' sh -lc 'cd /home/orbit/orbit && ORBIT_CONFIG_ROOT='\\''/home/orbit/.config/orbit'\\'' DB_CONNECTION=sqlite DB_DATABASE='\\''/home/orbit/.config/orbit/gateway.sqlite'\\'' SESSION_DRIVER=file php apps/gateway/artisan migrate --force --no-interaction --ansi'");
    $gatewayRefresh = strpos($setup, "docker exec 'orbit-e2e-prepared-build-operator_gateway-gateway' tar -C '/home/orbit/orbit' -cf - . | docker exec -i 'orbit-e2e-prepared-build-operator_gateway-gateway-orbit-gateway' tar -C '/home/orbit/orbit' -xf -");
    $gatewayBootstrap = strpos($setup, 'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bootstrap-gateway-local gateway 10.6.0.2');

    expect($operatorComposer)->toBeInt()
        ->and($operatorMigrate)->toBeInt()
        ->and($gatewayMigrate)->toBeInt()
        ->and($gatewayRefresh)->toBeInt()
        ->and($gatewayBootstrap)->toBeInt()
        ->and($operatorComposer)->toBeLessThan($operatorMigrate)
        ->and($operatorMigrate)->toBeLessThan($gatewayMigrate)
        ->and($gatewayMigrate)->toBeLessThan($gatewayRefresh)
        ->and($gatewayRefresh)->toBeLessThan($gatewayBootstrap);

    expect($setup)
        ->toContain("cd /home/orbit/orbit && ORBIT_CONFIG_ROOT='\\''/home/orbit/.config/orbit'\\'' DB_CONNECTION=sqlite DB_DATABASE='\\''/home/orbit/.config/orbit/gateway.sqlite'\\'' SESSION_DRIVER=file php apps/gateway/artisan migrate --force --no-interaction --ansi")
        ->toContain('cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bootstrap-gateway-local gateway 10.6.0.2 --skip-gateway-service-install --skip-wireguard-install')
        ->toContain('sudo -iu orbit env ORBIT_GATEWAY_CONTAINER="${ORBIT_GATEWAY_CONTAINER:-}" ORBIT_E2E_DOCKER_NETWORK="${ORBIT_E2E_DOCKER_NETWORK:-}" ORBIT_CONFIG_ROOT="${ORBIT_CONFIG_ROOT:-/home/orbit/.config/orbit}" DB_CONNECTION="${DB_CONNECTION:-sqlite}" DB_DATABASE="${DB_DATABASE:-/home/orbit/.config/orbit/gateway.sqlite}" SESSION_DRIVER="${SESSION_DRIVER:-file}" bash -lc')
        ->toContain('/home/orbit/orbit/apps/cli')
        ->toContain('type=bind,source=*|type=bind,src=*)')
        ->toContain('/home/orbit/.config/orbit/.env')
        ->toContain('/home/orbit/.config/orbit/gateway.sqlite')
        ->toContain('/home/orbit/.config/orbit')
        ->toContain("ORBIT_CONFIG_ROOT='\\''/home/orbit/.config/orbit'\\'' DB_CONNECTION=sqlite DB_DATABASE='\\''/home/orbit/.config/orbit/gateway.sqlite'\\'' SESSION_DRIVER=file php apps/gateway/artisan tinker --execute")
        ->toContain('ORBIT_GATEWAY_URL=%s')
        ->toContain('http://gateway')
        ->toContain('LocalGatewaySettings::current()')
        ->toContain('127.0.0.1')
        ->toContain('::1')
        ->toContain('10.6.0.2')
        ->not->toContain("docker run -d --restart unless-stopped --name 'orbit-e2e-prepared-build-operator_gateway-operator-orbit-gateway'")
        ->not->toContain('cd /home/orbit/orbit && orbit gateway:add')
        ->not->toContain('rm -rf apps/gateway/storage')
        ->not->toContain('ln -sfn /home/orbit/.config/orbit apps/gateway/storage')
        ->not->toContain('rm -f apps/gateway/.env')
        ->not->toContain('ln -sfn /home/orbit/.config/orbit/.env apps/gateway/.env')
        ->not->toContain('rm -f apps/gateway/database/database.sqlite')
        ->not->toContain('ln -sfn /home/orbit/.config/orbit/gateway.sqlite apps/gateway/database/database.sqlite')
        ->not->toContain('php artisan migrate --force');
});

it('starts the build gateway scheduler before schedule doctor verification', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGateway);

    $setup = implode("\n", $commands);
    $bootstrap = strpos($setup, 'orbit:internal:bootstrap-gateway-local gateway 10.6.0.2');
    $scheduler = strpos($setup, "docker exec --detach --workdir '/home/orbit/orbit' 'orbit-e2e-prepared-build-operator_gateway-gateway-orbit-gateway' orbit orbit-scheduler");
    $doctor = strpos($setup, 'orbit doctor --node=gateway --family=schedule --restore --json');
    $artisanDoctor = strpos($setup, 'apps/gateway/artisan doctor');

    expect($bootstrap)->toBeInt()
        ->and($scheduler)->toBeInt()
        ->and($doctor)->toBeInt()
        ->and($artisanDoctor)->toBeFalse()
        ->and($bootstrap)->toBeLessThan($scheduler)
        ->and($scheduler)->toBeLessThan($doctor);
});

it('tolerates Docker build gateway schedule doctor self-call authorization failure', function (): void {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        if (str_contains($process->command, 'orbit doctor --node=gateway --family=schedule --restore --json')) {
            return Process::result(
                output: '{"error":{"code":"authorization_failed","message":"Peer identity unknown.","meta":[]}}',
                exitCode: 1,
            );
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGateway);

    expect($manifest)->toBe([
        ['role' => 'operator', 'container' => 'orbit-e2e-prepared-build-operator_gateway-operator', 'image' => 'orbit-e2e:operator_base'],
        ['role' => 'gateway', 'container' => 'orbit-e2e-prepared-build-operator_gateway-gateway', 'image' => 'orbit-e2e:gateway_base'],
    ]);
});

it('keeps the build gateway container marked without starting services before migration', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGateway);

    $gatewayRuntimeStart = collect($commands)
        ->first(fn (string $command): bool => str_contains($command, "docker run -d --restart unless-stopped --name 'orbit-e2e-prepared-build-operator_gateway-gateway-orbit-gateway'"));

    expect($gatewayRuntimeStart)->toBeString()
        ->and($gatewayRuntimeStart)->toContain('ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit')
        ->and($gatewayRuntimeStart)->toContain("'orbit-gateway:prepared-current' tail -f /dev/null");
});

it('normalizes persisted gateway orbit state ownership before committing prepared images', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGateway);

    $persist = collect($commands)->first(fn (string $command): bool => str_contains($command, "docker exec -i 'orbit-e2e-prepared-build-operator_gateway-gateway' tar -C '/home/orbit/orbit' -xf -"));
    $ownershipCommands = collect($commands)->filter(fn (string $command): bool => str_contains($command, "docker exec 'orbit-e2e-prepared-build-operator_gateway-gateway'")
        && str_contains($command, 'chown -R orbit:orbit')
        && str_contains($command, '/home/orbit/.config/orbit'))->values();
    $ownershipBeforeMigration = $ownershipCommands->first();
    $ownershipBeforeCommit = $ownershipCommands->last();
    $migration = collect($commands)->first(fn (string $command): bool => str_contains($command, "docker exec --user 'orbit' 'orbit-e2e-prepared-build-operator_gateway-gateway' sh -lc")
        && str_contains($command, 'php apps/gateway/artisan migrate --force --no-interaction --ansi'));
    $commit = collect($commands)->first(fn (string $command): bool => str_contains($command, 'docker commit')
        && str_contains($command, "'orbit-e2e-prepared-build-operator_gateway-gateway'")
        && str_contains($command, 'gateway_base'));

    expect($persist)->toBeString()
        ->and($ownershipCommands->count())->toBeGreaterThanOrEqual(2)
        ->and($ownershipBeforeMigration)->toBeString()
        ->toContain('install -d -m 775 -o orbit -g orbit')
        ->toContain('chmod -R u+rwX,g+rwX')
        ->and($ownershipBeforeCommit)->toBeString()
        ->toContain('install -d -m 775 -o orbit -g orbit')
        ->toContain('chmod -R u+rwX,g+rwX')
        ->and($migration)->toBeString()
        ->toContain('ORBIT_CONFIG_ROOT=')
        ->toContain('DB_CONNECTION=sqlite')
        ->toContain('DB_DATABASE=')
        ->toContain('/home/orbit/.config/orbit/gateway.sqlite')
        ->and($commit)->toBeString()
        ->and(array_search($ownershipBeforeMigration, $commands, strict: true))->toBeLessThan(array_search($migration, $commands, strict: true))
        ->and(array_search($persist, $commands, strict: true))->toBeLessThan(array_search($ownershipBeforeCommit, $commands, strict: true))
        ->and(array_search($ownershipBeforeCommit, $commands, strict: true))->toBeLessThan(array_search($commit, $commands, strict: true));
});

it('commits Docker build topology images from node image-layer state instead of mounted home volumes', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGateway);

    $setup = implode("\n", $commands);
    $operatorSync = strpos($setup, "docker exec --user 'orbit' 'orbit-e2e-prepared-build-operator_gateway-operator' sh -lc 'cd /home/orbit/orbit && ORBIT_CONFIG_ROOT='\\''/home/orbit/.config/orbit'\\'' DB_CONNECTION=sqlite DB_DATABASE='\\''/home/orbit/.config/orbit/gateway.sqlite'\\'' SESSION_DRIVER=file php apps/gateway/artisan migrate --force --no-interaction --ansi'");
    $operatorCommit = strpos($setup, "docker commit --change 'CMD [\"/usr/local/bin/orbit-e2e-container\"]' --change 'LABEL org.orbit.e2e.topology-mode=dns-alias' --change 'LABEL org.orbit.e2e.kind=operator_gateway' --change 'LABEL org.orbit.e2e.role=operator'");
    $gatewaySync = strpos($setup, "docker exec 'orbit-e2e-prepared-build-operator_gateway-gateway-orbit-gateway' tar -C '/home/orbit/orbit' -cf - . | docker exec -i 'orbit-e2e-prepared-build-operator_gateway-gateway' tar -C '/home/orbit/orbit' -xf -");
    $gatewayCommit = strpos($setup, "docker commit --change 'CMD [\"/usr/local/bin/orbit-e2e-container\"]' --change 'LABEL org.orbit.e2e.topology-mode=dns-alias' --change 'LABEL org.orbit.e2e.kind=operator_gateway' --change 'LABEL org.orbit.e2e.role=gateway'");

    expect($setup)
        ->not->toContain("--mount 'type=volume,src=orbit-e2e-prepared-build-operator_gateway-operator-home-operator,dst=/home/operator'")
        ->not->toContain("--mount 'type=volume,src=orbit-e2e-prepared-build-operator_gateway-gateway-home-orbit,dst=/home/orbit'");

    expect($operatorSync)->toBeInt()
        ->and($operatorCommit)->toBeInt()
        ->and($operatorSync)->toBeLessThan($operatorCommit)
        ->and($gatewaySync)->toBeInt()
        ->and($gatewayCommit)->toBeInt()
        ->and($gatewaySync)->toBeLessThan($gatewayCommit);
});

it('does not use host PHP or host Caddy paths while building Docker gateway topology state', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGateway);

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain('php apps/gateway/artisan tinker --execute=')
        ->toContain('php -d display_errors=0 -d max_execution_time=0 -S')
        ->not->toContain('orbit serve --host=')
        ->not->toContain('php artisan')
        ->not->toContain('nohup php')
        ->not->toContain('php -r')
        ->not->toContain('systemctl stop caddy');
});

it('defines downstream small topology role matrices for current roles', function (): void {
    expect(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdev))->toBe(['operator', 'gateway', 'dev'])
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAgent))->toBe(['operator', 'gateway', 'agent'])
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprod))->toBe(['operator', 'gateway', 'dev', 'prod'])
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent))->toBe(['operator', 'gateway', 'dev', 'prod', 'agent'])
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress))->toBe(['operator', 'gateway', 'dev', 'prod', 'ingress'])
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppprodIngress))->toBe(['operator', 'gateway', 'prod'])
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdevWebsocket))->toBe(['operator', 'gateway', 'dev'])
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket))->toBe(['operator', 'gateway', 'dev', 'prod'])
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket))->toBe(['operator', 'gateway', 'dev', 'prod', 'agent']);
});

it('does not accept bare client aliases for downstream small topology fixtures', function (): void {
    expect(E2ETopologyKind::tryFromInput('client-gateway-appdev'))->toBeNull();
});

it('seeds appdev docker topology with database role and Redis process for downstream service tests', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGatewayAppdev);

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain('NodeRoleName::Database')
        ->toContain('Process::query()->updateOrCreate')
        ->toContain('redis')
        ->toContain('definition')
        ->toContain('7.2');
});

it('provisions Docker downstream role source images in parallel after the gateway baseline', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent);

    $gatewayBootstrap = collect($commands)
        ->first(fn (string $command): bool => str_contains($command, 'orbit:internal:bootstrap-gateway-local gateway 10.6.0.2'));
    $scriptWrite = collect($commands)
        ->first(fn (string $command): bool => str_contains($command, 'cat >')
            && str_contains($command, 'orbit-e2e-docker-downstream.sh'));
    $scriptRun = collect($commands)
        ->first(fn (string $command): bool => str_contains($command, 'sudo -iu orbit env')
            && str_contains($command, '/tmp/orbit-e2e-docker-downstream.sh'));

    expect($gatewayBootstrap)->toBeString()
        ->and($scriptWrite)->toBeString()
        ->and($scriptRun)->toBeString()
        ->and(array_search($gatewayBootstrap, $commands, strict: true))->toBeLessThan(array_search($scriptWrite, $commands, strict: true))
        ->and(array_search($scriptWrite, $commands, strict: true))->toBeLessThan(array_search($scriptRun, $commands, strict: true));

    expect($scriptWrite)
        ->toContain('orbit:internal:bake-app-node app-dev-1')
        ->toContain('orbit:internal:bake-ingress-node app-prod-1')
        ->toContain('orbit:internal:bake-app-node app-prod-1')
        ->toContain('orbit:internal:bake-agent-node agent-1')
        ->toContain('PID_NODE_NEW_DEV=$!;')
        ->toContain('PID_NODE_NEW_PROD=$!;')
        ->toContain('PID_NODE_NEW_AGENT=$!;')
        ->toContain('wait "$PID_NODE_NEW_DEV"')
        ->toContain('wait "$PID_NODE_NEW_PROD"')
        ->toContain('wait "$PID_NODE_NEW_AGENT"')
        ->toContain('/tmp/orbit-e2e-docker-node-new-dev.log')
        ->toContain('/tmp/orbit-e2e-docker-node-new-prod.log')
        ->toContain('/tmp/orbit-e2e-docker-node-new-agent.log');

    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, "docker exec --user 'orbit'")
        && (str_contains($process->command, 'bake-app-node') || str_contains($process->command, 'bake-agent-node')));
});

it('bakes the Docker websocket role onto app-dev after app development Redis is registered', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);

    $scriptWrite = collect($commands)
        ->first(fn (string $command): bool => str_contains($command, 'cat >')
            && str_contains($command, 'orbit-e2e-docker-downstream.sh'));

    expect($scriptWrite)
        ->toBeString()
        ->toContain('orbit:internal:bake-websocket-node app-dev-1')
        ->toContain('--host=dev')
        ->toContain('--host-key-host=')
        ->toContain('--wireguard-address=10.6.0.4')
        ->toContain('--gateway-endpoint=gateway')
        ->toContain('--redis-node=app-dev-1')
        ->toContain('PID_NODE_NEW_WEBSOCKET=$!')
        ->toContain('wait "$PID_NODE_NEW_WEBSOCKET"')
        ->toContain('/tmp/orbit-e2e-docker-node-new-websocket.log');

    expect(strpos($scriptWrite, 'wait "$PID_NODE_NEW_DEV"'))
        ->toBeLessThan(strpos($scriptWrite, 'orbit:internal:bake-websocket-node app-dev-1'));

    expect($scriptWrite)->not->toContain('--environment=');
});

it('builds operator_gateway prepared images through transient docker resources', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-gateway:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'composer:2' >/dev/null" => Process::result(),
        "docker network create --subnet * 'orbit-e2e-prepared-build-operator_gateway'" => Process::result(),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --group-add * --name 'orbit-e2e-prepared-build-operator_gateway-operator' *" => Process::result(output: "operator-id\n"),
        "docker run -d --name 'orbit-e2e-prepared-build-operator_gateway-operator-composer' *" => Process::result(output: "composer-id\n"),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --group-add * --name 'orbit-e2e-prepared-build-operator_gateway-gateway' *" => Process::result(output: "gateway-id\n"),
        "docker run -d --restart unless-stopped --name 'orbit-e2e-prepared-build-operator_gateway-gateway-orbit-gateway' *" => Process::result(output: "gateway-id\n"),
        'docker exec *ip addr add*' => Process::result(),
        'docker exec *ORBIT_E2E_GATEWAY_DOCKER_SHIM*' => Process::result(),
        'docker exec *rm -rf*install -d*' => Process::result(),
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
        'docker exec -i *tar --warning=no-unknown-keyword*orbit-current-*' => Process::result(),
        'docker exec *mkdir -p*' => Process::result(),
        'docker exec *tar -C*' => Process::result(),
        'docker exec *ln -sfn*' => Process::result(),
        'docker exec *chown -R orbit:orbit*' => Process::result(),
        'docker exec --env *composer install*' => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-prepared-build-operator_gateway-operator' sh -lc *migrate*" => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-prepared-build-operator_gateway-gateway' sh -lc *migrate*" => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-prepared-build-operator_gateway-gateway' sh -lc *bootstrap-gateway-local*" => Process::result(),
        "docker exec --detach --workdir '/home/orbit/orbit' 'orbit-e2e-prepared-build-operator_gateway-gateway-orbit-gateway' orbit orbit-scheduler" => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-prepared-build-operator_gateway-gateway' sh -lc *orbit doctor*--family=schedule*" => Process::result(),
        "docker exec 'orbit-e2e-prepared-build-operator_gateway-gateway' sh -lc *tinker*" => Process::result(),
        "docker exec 'orbit-e2e-prepared-build-operator_gateway-gateway' sh -lc *cat*" => Process::result(),
        "docker exec 'orbit-e2e-prepared-build-operator_gateway-gateway' sh -lc 'if [ -f /home/orbit/.ssh/id_ed25519 ]; then install -d -m 700 /root/.ssh && cp /home/orbit/.ssh/id_ed25519 /root/.ssh/id_ed25519 && chmod 600 /root/.ssh/id_ed25519 && if [ -f /home/orbit/.ssh/id_ed25519.pub ]; then cp /home/orbit/.ssh/id_ed25519.pub /root/.ssh/id_ed25519.pub; fi; fi'" => Process::result(),
        "docker exec 'orbit-e2e-prepared-build-operator_gateway-gateway' sh -lc *sudo docker exec*id_ed25519*" => Process::result(),
        "docker exec 'orbit-e2e-prepared-build-operator_gateway-gateway' sh -lc *php -d display_errors=0 -d max_execution_time=0 -S*" => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-prepared-build-operator_gateway-operator' sh -lc *curl*" => Process::result(),
        "docker exec --user 'orbit' 'orbit-e2e-prepared-build-operator_gateway-operator' sh -lc *tinker*" => Process::result(),
        'docker exec *ORBIT_GATEWAY_URL*' => Process::result(),
        "docker commit --change * 'orbit-e2e-prepared-build-operator_gateway-operator' 'orbit-e2e:operator_base'" => Process::result(),
        "docker commit --change * 'orbit-e2e-prepared-build-operator_gateway-gateway' 'orbit-e2e:gateway_base'" => Process::result(),
        "docker rm -f 'orbit-e2e-prepared-build-operator_gateway-operator-composer' 'orbit-e2e-prepared-build-operator_gateway-operator-orbit-caddy' 'orbit-e2e-prepared-build-operator_gateway-operator' >/dev/null 2>&1 || true" => Process::result(),
        "docker rm -f 'orbit-e2e-prepared-build-operator_gateway-gateway-orbit-gateway' 'orbit-e2e-prepared-build-operator_gateway-gateway-orbit-caddy' 'orbit-e2e-prepared-build-operator_gateway-gateway' >/dev/null 2>&1 || true" => Process::result(),
        'docker volume rm -f *' => Process::result(),
        "docker network rm 'orbit-e2e-prepared-build-operator_gateway' >/dev/null 2>&1 || true" => Process::result(),
    ]);

    $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGateway);

    expect($manifest)->toBe([
        ['role' => 'operator', 'container' => 'orbit-e2e-prepared-build-operator_gateway-operator', 'image' => 'orbit-e2e:operator_base'],
        ['role' => 'gateway', 'container' => 'orbit-e2e-prepared-build-operator_gateway-gateway', 'image' => 'orbit-e2e:gateway_base'],
    ]);

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'ORBIT_E2E_GATEWAY_DOCKER_SHIM')
        && str_contains($process->command, 'ORBIT_E2E_RUNTIME_DOCKER_SHIM')
        && str_contains($process->command, 'elif [ ! -x /usr/bin/docker.real ]; then')
        && str_contains($process->command, '${node_container}-home-orbit')
        && str_contains($process->command, '/home/orbit/*)')
        && str_contains($process->command, 'rewrite_volume'));
    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker commit')
        && $process->timeout === 600
        && str_contains($process->command, 'CMD ["/usr/local/bin/orbit-e2e-container"]')
        && str_contains($process->command, 'org.orbit.e2e.topology-mode=dns-alias')
        && str_contains($process->command, 'org.orbit.e2e.cert-san-set=DNS:gateway,IP:10.6.0.2')
        && str_contains($process->command, "'orbit-e2e-prepared-build-operator_gateway-operator'")
        && str_contains($process->command, "'orbit-e2e:operator_base'"));
    Process::assertRan("docker network rm 'orbit-e2e-prepared-build-operator_gateway' >/dev/null 2>&1 || true");
});

it('seeds gateway to app node ssh access for remote shell feature tests', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-gateway:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'composer:2' >/dev/null" => Process::result(),
        "docker network create --subnet * 'orbit-e2e-prepared-build-operator_gateway_app-dev'" => Process::result(),
        'docker run -d *' => Process::result(output: "container-id\n"),
        'docker exec *ip addr add*' => Process::result(),
        'docker exec *rm -rf*install -d*' => Process::result(),
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
        'docker exec -i *tar --warning=no-unknown-keyword*orbit-current-*' => Process::result(),
        'docker exec *mkdir -p*' => Process::result(),
        'docker exec *tar -C*' => Process::result(),
        'docker exec *ln -sfn*' => Process::result(),
        'docker exec *chown -R orbit:orbit*' => Process::result(),
        'docker exec --env *composer install*' => Process::result(),
        'docker exec --user *migrate*' => Process::result(),
        'docker exec --user *bootstrap-gateway-local*' => Process::result(),
        'docker exec --detach *orbit-scheduler' => Process::result(),
        'docker exec --user *doctor*--family=schedule*' => Process::result(),
        'docker exec *tinker*' => Process::result(),
        'docker exec *php -d display_errors=0 -d max_execution_time=0 -S*' => Process::result(),
        'docker exec --user *curl*' => Process::result(),
        'docker exec *ORBIT_GATEWAY_URL*' => Process::result(),
        'docker exec *ssh-keygen*' => Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n"),
        'docker exec *id_ed25519*' => Process::result(),
        'docker exec *cat*' => Process::result(),
        'docker exec *orbit-e2e-docker-downstream.sh*' => Process::result(),
        "docker exec 'orbit-e2e-prepared-build-operator_gateway_app-dev-gateway' sh -lc 'if [ -f /home/orbit/.ssh/id_ed25519 ]; then install -d -m 700 /root/.ssh && cp /home/orbit/.ssh/id_ed25519 /root/.ssh/id_ed25519 && chmod 600 /root/.ssh/id_ed25519 && if [ -f /home/orbit/.ssh/id_ed25519.pub ]; then cp /home/orbit/.ssh/id_ed25519.pub /root/.ssh/id_ed25519.pub; fi; fi'" => Process::result(),
        "docker exec 'orbit-e2e-prepared-build-operator_gateway_app-dev-dev' sh -lc *authorized_keys*" => Process::result(),
        'docker exec --user *ssh-keyscan*' => Process::result(),
        'docker exec --user *bake-ingress-node*' => Process::result(),
        'docker exec --user *bake-app-node*' => Process::result(),
        'docker exec --user *bake-agent-node*' => Process::result(),
        'docker commit --change *' => Process::result(),
        'docker rm -f *' => Process::result(),
        'docker volume rm -f *' => Process::result(),
        'docker network rm *' => Process::result(),
    ]);

    (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGatewayAppdev);

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'ssh-keygen -t ed25519')
        && str_contains($process->command, 'cat ~/.ssh/id_ed25519.pub'));
    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'authorized_keys')
        && str_contains($process->command, 'ssh-ed25519 AAAATEST orbit-e2e-gateway'));
});

it('uses the configured instance prefix for transient resources but stable image tags', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-gateway:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'composer:2' >/dev/null" => Process::result(),
        "docker network create --subnet * 'ci-foo-prepared-build-operator_gateway'" => Process::result(),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --group-add * --name 'ci-foo-prepared-build-operator_gateway-operator' *" => Process::result(output: "operator-id\n"),
        "docker run -d --name 'ci-foo-prepared-build-operator_gateway-operator-composer' *" => Process::result(output: "composer-id\n"),
        "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --group-add * --name 'ci-foo-prepared-build-operator_gateway-gateway' *" => Process::result(output: "gateway-id\n"),
        "docker run -d --restart unless-stopped --name 'ci-foo-prepared-build-operator_gateway-gateway-orbit-gateway' *" => Process::result(output: "runtime-id\n"),
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
        'docker exec -i *tar --warning=no-unknown-keyword*orbit-current-*' => Process::result(),
        'docker exec --user *' => Process::result(),
        'docker exec *' => Process::result(),
        "docker commit --change * 'ci-foo-prepared-build-operator_gateway-operator' 'orbit-e2e:operator_base'" => Process::result(),
        "docker commit --change * 'ci-foo-prepared-build-operator_gateway-gateway' 'orbit-e2e:gateway_base'" => Process::result(),
        "docker rm -f 'ci-foo-prepared-build-operator_gateway-operator-composer' 'ci-foo-prepared-build-operator_gateway-operator-orbit-caddy' 'ci-foo-prepared-build-operator_gateway-operator' >/dev/null 2>&1 || true" => Process::result(),
        "docker rm -f 'ci-foo-prepared-build-operator_gateway-gateway-orbit-gateway' 'ci-foo-prepared-build-operator_gateway-gateway-orbit-caddy' 'ci-foo-prepared-build-operator_gateway-gateway' >/dev/null 2>&1 || true" => Process::result(),
        'docker volume rm -f *' => Process::result(),
        "docker network rm 'ci-foo-prepared-build-operator_gateway' >/dev/null 2>&1 || true" => Process::result(),
    ]);

    withE2EEnvironment(['ORBIT_E2E_INSTANCE_PREFIX'], [
        'ORBIT_E2E_INSTANCE_PREFIX' => 'ci-foo',
    ], function (): void {
        $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
            ->build(E2ETopologyKind::OperatorGateway);

        expect($manifest)->toBe([
            ['role' => 'operator', 'container' => 'ci-foo-prepared-build-operator_gateway-operator', 'image' => 'orbit-e2e:operator_base'],
            ['role' => 'gateway', 'container' => 'ci-foo-prepared-build-operator_gateway-gateway', 'image' => 'orbit-e2e:gateway_base'],
        ]);
    });
});

it('bakes dns alias topology registry data into stable role images', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-gateway:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'composer:2' >/dev/null" => Process::result(),
        "docker network create --subnet * 'orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent'" => Process::result(),
        'docker run -d *' => Process::result(output: "container-id\n"),
        'docker exec *ip addr add*' => Process::result(),
        'docker exec *rm -rf*install -d*' => Process::result(),
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
        'docker exec -i *tar --warning=no-unknown-keyword*orbit-current-*' => Process::result(),
        'docker exec *mkdir -p*' => Process::result(),
        'docker exec *tar -C*' => Process::result(),
        'docker exec *ln -sfn*' => Process::result(),
        'docker exec *chown -R orbit:orbit*' => Process::result(),
        'docker exec --env *composer install*' => Process::result(),
        'docker exec --user *migrate*' => Process::result(),
        'docker exec --user *bootstrap-gateway-local*' => Process::result(),
        'docker exec --detach *orbit-scheduler' => Process::result(),
        'docker exec --user *doctor*--family=schedule*' => Process::result(),
        'docker exec *tinker*' => Process::result(),
        'docker exec *php -d display_errors=0 -d max_execution_time=0 -S*' => Process::result(),
        'docker exec --user *curl*' => Process::result(),
        'docker exec *ORBIT_GATEWAY_URL*' => Process::result(),
        'docker exec *ssh-keygen*' => Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n"),
        'docker exec *id_ed25519*' => Process::result(),
        'docker exec *cat*' => Process::result(),
        'docker exec *orbit-e2e-docker-downstream.sh*' => Process::result(),
        'docker exec *authorized_keys*' => Process::result(),
        'docker exec --user *ssh-keyscan*' => Process::result(),
        'docker exec --user *bake-ingress-node*' => Process::result(),
        'docker exec --user *bake-app-node*' => Process::result(),
        'docker exec --user *bake-agent-node*' => Process::result(),
        'docker commit --change *' => Process::result(),
        'docker rm -f *' => Process::result(),
        'docker volume rm -f *' => Process::result(),
        'docker network rm *' => Process::result(),
    ]);

    $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent, 'dns-alias');

    expect($manifest[0]['image'])->toBe('orbit-e2e:operator_base')
        ->and($manifest[0]['reused'])->toBeTrue()
        ->and($manifest[2]['image'])->toBe('orbit-e2e:app-dev_base')
        ->and($manifest[2])->not->toHaveKey('reused')
        ->and($manifest[3]['image'])->toBe('orbit-e2e:app-prod_base')
        ->and($manifest[3])->not->toHaveKey('reused');

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'orbit:internal:bake-app-node app-dev-1')
        && str_contains($process->command, '--role=app-dev')
        && str_contains($process->command, '--host=dev')
        && str_contains($process->command, '--gateway-endpoint=gateway')
        && ! str_contains($process->command, '--environment=development')
        && ! str_contains($process->command, '--host=10.6.0.4'));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, '/home/orbit/orbit/apps/cli')
        && str_contains($process->command, 'ORBIT_GATEWAY_URL=%s')
        && str_contains($process->command, 'http://gateway')
        && str_contains($process->command, 'http://gateway/api/ca/root')
        && str_contains($process->command, 'https://gateway')
        && str_contains($process->command, 'LocalGatewaySettings::current()')
        && str_contains($process->command, 'ca_sha256')
        && str_contains($process->command, 'ca_pem_path'));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker commit')
        && str_contains($process->command, 'org.orbit.e2e.topology-mode=dns-alias')
        && str_contains($process->command, 'org.orbit.e2e.cert-san-set=DNS:gateway,IP:10.6.0.2'));
});

it('bakes app production ingress docker topology registry data without dev or agent roles', function (): void {
    Process::fake([
        "docker image inspect 'orbit-e2e-topology-runtime:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-gateway:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'composer:2' >/dev/null" => Process::result(),
        "docker network create --subnet * 'orbit-e2e-prepared-build-operator_gateway_app-prod_ingress'" => Process::result(),
        'docker run -d *' => Process::result(output: "container-id\n"),
        'docker exec *ip addr add*' => Process::result(),
        'docker exec *rm -rf*install -d*' => Process::result(),
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
        'docker exec -i *tar --warning=no-unknown-keyword*orbit-current-*' => Process::result(),
        'docker exec *mkdir -p*' => Process::result(),
        'docker exec *tar -C*' => Process::result(),
        'docker exec *ln -sfn*' => Process::result(),
        'docker exec *chown -R orbit:orbit*' => Process::result(),
        'docker exec --env *composer install*' => Process::result(),
        'docker exec --user *migrate*' => Process::result(),
        'docker exec --user *bootstrap-gateway-local*' => Process::result(),
        'docker exec --detach *orbit-scheduler' => Process::result(),
        'docker exec --user *doctor*--family=schedule*' => Process::result(),
        'docker exec *tinker*' => Process::result(),
        'docker exec *php -d display_errors=0 -d max_execution_time=0 -S*' => Process::result(),
        'docker exec --user *curl*' => Process::result(),
        'docker exec *ORBIT_GATEWAY_URL*' => Process::result(),
        'docker exec *ssh-keygen*' => Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n"),
        'docker exec *id_ed25519*' => Process::result(),
        'docker exec *cat*' => Process::result(),
        'docker exec *orbit-e2e-docker-downstream.sh*' => Process::result(),
        'docker exec *authorized_keys*' => Process::result(),
        'docker exec --user *ssh-keyscan*' => Process::result(),
        'docker exec --user *bake-ingress-node*' => Process::result(),
        'docker exec --user *bake-app-node*' => Process::result(),
        'docker commit --change *' => Process::result(),
        'docker rm -f *' => Process::result(),
        'docker volume rm -f *' => Process::result(),
        'docker network rm *' => Process::result(),
    ]);

    $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
        ->build(E2ETopologyKind::OperatorGatewayAppprodIngress, 'dns-alias');

    $networkPlan = DockerTopologyNetworkPlan::fromEnvironment('orbit-e2e-prepared-build-operator_gateway_app-prod_ingress');
    $prodIp = $networkPlan->ipForRole('prod');

    expect(array_column($manifest, 'role'))->toBe(['operator', 'gateway', 'prod'])
        ->and($manifest[0]['image'])->toBe('orbit-e2e:operator_base')
        ->and($manifest[0]['reused'])->toBeTrue()
        ->and($manifest[2]['image'])->toBe('orbit-e2e:app-prod_base')
        ->and($manifest[2]['reused'])->toBeTrue();

    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, "--name 'orbit-e2e-prepared-build-operator_gateway_app-prod_ingress-ingress'"));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'orbit:internal:bootstrap-gateway-local gateway 10.6.0.2')
        && str_contains($process->command, '--skip-wireguard-install'));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'ssh-keyscan -T 2')
        && str_contains($process->command, $prodIp));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'orbit:internal:bake-ingress-node app-prod-1')
        && str_contains($process->command, '--host=prod')
        && str_contains($process->command, '--host-key-host=')
        && str_contains($process->command, $prodIp)
        && str_contains($process->command, '10.6.0.5')
        && str_contains($process->command, '--wireguard-address=10.6.0.5'));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'orbit:internal:bake-app-node app-prod-1')
        && str_contains($process->command, '--role=app-prod')
        && str_contains($process->command, '--host=prod')
        && str_contains($process->command, '--host-key-host=')
        && str_contains($process->command, $prodIp)
        && ! str_contains($process->command, '--environment=production')
        && str_contains($process->command, '--ingress-node=app-prod-1'));

    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && (str_contains($process->command, 'app-dev-1') || str_contains($process->command, 'agent-1')));
});

it('bakes prepared app production image with a colocated ingress role', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, 'ssh-keygen -t ed25519') || str_contains($process->command, 'id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    withE2ETopologyEnvironment([], function (): void {
        $manifest = (new DockerTopologyBuilder(E2EConfig::fromEnvironment()))
            ->build(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent, 'dns-alias');

        expect(array_column($manifest, 'role'))->toBe(['operator', 'gateway', 'dev', 'prod', 'agent'])
            ->and($manifest[0]['image'])->toBe('orbit-e2e:operator_base')
            ->and($manifest[0]['reused'])->toBeTrue()
            ->and($manifest[1]['image'])->toBe('orbit-e2e:gateway_base')
            ->and($manifest[1]['reused'])->toBeTrue()
            ->and($manifest[2]['image'])->toBe('orbit-e2e:app-dev_base')
            ->and($manifest[2])->not->toHaveKey('reused')
            ->and($manifest[3]['image'])->toBe('orbit-e2e:app-prod_base')
            ->and($manifest[3])->not->toHaveKey('reused')
            ->and($manifest[4]['image'])->toBe('orbit-e2e:agent_base')
            ->and($manifest[4])->not->toHaveKey('reused');
    });

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain('orbit:internal:bake-ingress-node app-prod-1')
        ->toContain('--host=prod')
        ->toContain('--wireguard-address=10.6.0.5')
        ->toContain('orbit:internal:bake-app-node app-prod-1')
        ->toContain('--ingress-node=app-prod-1')
        ->not->toContain('orbit:internal:bake-ingress-node edge-1')
        ->not->toContain('orbit-e2e-prepared-build-operator_gateway_app-dev_app-prod_agent-ingress');
});
