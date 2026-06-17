<?php

declare(strict_types=1);

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerInstance;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

it('installs provisioning SSH keys for gateway API container users', function (): void {
    $privateKey = tempnam(sys_get_temp_dir(), 'orbit-e2e-key-');

    if (! is_string($privateKey)) {
        throw new RuntimeException('Could not create temporary SSH key.');
    }

    $publicKey = "{$privateKey}.pub";

    file_put_contents($privateKey, 'private-key');
    file_put_contents($publicKey, 'public-key');

    $instance = new class implements E2EInstance
    {
        /** @var list<array{source: string, target: string}> */
        public array $copies = [];

        /** @var list<string> */
        public array $commands = [];

        public function name(): string
        {
            return 'gateway';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return Process::result();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return Process::result();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void
        {
            $this->copies[] = ['source' => $sourcePath, 'target' => $targetPath];
        }

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.6.0.2';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };

    try {
        E2EGatewayApi::installProvisioningSshKey($instance, new SshKeyPair($privateKey, $publicKey));

        expect(array_column($instance->copies, 'target'))->toBe([
            '/root/.ssh/id_ed25519',
            '/home/orbit/.ssh/id_ed25519',
            '/var/www/.ssh/id_ed25519',
        ]);

        $gatewayKeyInstall = collect($instance->commands)->first(fn (string $command): bool => str_contains($command, 'orbit-gateway')
            && str_contains($command, 'docker cp')
            && str_contains($command, '/root/.ssh/id_ed25519'));

        expect($gatewayKeyInstall)->toBeString()
            ->toContain("docker inspect --format='{{.State.Running}}' 'orbit-gateway'")
            ->toContain('gateway_private_key=/home/orbit/.ssh/id_ed25519')
            ->toContain("docker cp \"\$gateway_private_key\" 'orbit-gateway:/root/.ssh/id_ed25519'")
            ->toContain("docker exec 'orbit-gateway' sh -lc 'chown root:root /root/.ssh/id_ed25519");
    } finally {
        @unlink($privateKey);
        @unlink($publicKey);
    }
});

it('seeds operator identity with a gateway admin grant', function (): void {
    $instance = new class implements E2EInstance
    {
        /** @var list<string> */
        public array $commands = [];

        public function name(): string
        {
            return 'gateway';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return Process::result();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return Process::result();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.6.0.2';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };

    E2EGatewayApi::seedOperatorIdentity($instance, '10.6.0.3', 'orbit');

    $seedCommand = collect($instance->commands)->first(fn (string $command): bool => str_contains($command, 'orbit-gateway:prepared-current')
        && str_contains($command, 'artisan tinker --execute='));

    expect($seedCommand)->toBeString()
        ->toContain('docker run --rm --pull never')
        ->toContain('ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit')
        ->toContain('DB_CONNECTION=sqlite')
        ->toContain('DB_DATABASE=/home/orbit/.config/orbit/gateway.sqlite')
        ->toContain('SESSION_DRIVER=file')
        ->toContain('type=bind,source=/home/orbit/.config/orbit,target=/home/orbit/.config/orbit')
        ->toContain('orbit-gateway:prepared-current')
        ->toContain('artisan tinker --execute=')
        ->toContain('chown -R orbit:orbit')
        ->toContain('/home/orbit/.config/orbit')
        ->not->toContain('cd /home/orbit/orbit')
        ->not->toContain('php apps/gateway/artisan');

    $script = gatewayDecodedTinkerPayload(implode("\n", $instance->commands));

    expect($script)
        ->toContain("'platform' => 'ubuntu'")
        ->toContain('NodeAccess::query()->updateOrCreate')
        ->toContain('activeGatewayNodeQuery()')
        ->toContain('permissions')
        ->toContain('custom_permissions')
        ->toContain('*')
        ->not->toContain("'role' =>")
        ->not->toContain("'environment' =>");
});

it('repairs docker gateway config root write permissions before seeding operator identity', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands): ProcessResult {
        $commands[] = (string) $process->command;

        return Process::result();
    });
    Process::preventStrayProcesses();

    $gateway = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-gateway');

    E2EGatewayApi::seedOperatorIdentity($gateway, 'gateway', 'orbit');

    $setup = implode("\n", $commands);
    $repairPosition = strpos($setup, 'chmod -R u+rwX,g+rwX');
    $removeTmpPosition = strpos($setup, 'rm -f');
    $seedPosition = strpos($setup, 'grep -Ev');
    $sudoPosition = strpos($setup, 'sudo -iu orbit');
    $migratePosition = strpos($setup, 'php apps/gateway/artisan migrate --force --no-interaction --ansi');
    $lastRepairPosition = strrpos($setup, 'chmod -R u+rwX,g+rwX');

    expect($repairPosition)->toBeInt()
        ->and($removeTmpPosition)->toBeInt()
        ->and($seedPosition)->toBeInt()
        ->and($sudoPosition)->toBeInt()
        ->and($migratePosition)->toBeInt()
        ->and($lastRepairPosition)->toBeInt()
        ->and($repairPosition)->toBeLessThan($seedPosition)
        ->and($removeTmpPosition)->toBeLessThan($seedPosition)
        ->and($seedPosition)->toBeLessThan($sudoPosition)
        ->and($sudoPosition)->toBeLessThan($migratePosition)
        ->and($migratePosition)->toBeLessThan($lastRepairPosition)
        ->and($setup)->toContain('install -d -m 775 -o orbit -g orbit')
        ->and($setup)->toContain('chown -R orbit:orbit')
        ->and($setup)->toContain('chmod -R u+rwX,g+rwX')
        ->and($setup)->toContain('/home/orbit/.config/orbit/.env')
        ->and($setup)->toContain('/home/orbit/.config/orbit/.env.tmp')
        ->and($setup)->toContain('.tmp');
});

it('proxies gateway api routes to Laravel before legacy command shims', function (): void {
    $reflection = new ReflectionClass(E2EGatewayApi::class);
    $method = $reflection->getMethod('tlsServerScript');
    $method->setAccessible(true);

    $script = $method->invoke(null, '/home/orbit/orbit-current', '10.6.0.2', '10.6.0.2', '10.6.0.2');
    $apiProxyPosition = strpos($script, "preg_match('#^(GET|POST|PUT|PATCH|DELETE) /api/#', \$requestLine) === 1");
    $legacyNodeListPosition = strpos($script, "str_starts_with(\$requestLine, 'GET /api/nodes ')");

    expect($script)
        ->toContain('proxy_to_laravel($connection, $requestLine, $headers, $body);')
        ->not->toContain('php apps/gateway/artisan tool:logs')
        ->not->toContain('stream_tool_logs')
        ->toContain('sudo -iu orbit bash -lc')
        ->and($apiProxyPosition)->toBeInt()
        ->and($legacyNodeListPosition)->toBeInt()
        ->and($apiProxyPosition)->toBeLessThan($legacyNodeListPosition);
});

it('runs Docker gateway api shim commands directly inside orbit gateway', function (): void {
    $reflection = new ReflectionClass(E2EGatewayApi::class);
    $method = $reflection->getMethod('tlsServerScript');
    $method->setAccessible(true);

    $script = $method->invoke(null, '/home/orbit/orbit', '10.6.0.2', '0.0.0.0', 'gateway', [], true);

    expect($script)
        ->toContain("exec(\$script.' 2>&1'")
        ->toContain("\$certDirectory = '/home/orbit/.config/orbit/certs';")
        ->toContain("\$GLOBALS['httpUpstream'] = \$httpUpstream;")
        ->toContain("\$GLOBALS['wireguardIdentity'] = \$wireguardIdentity;")
        ->not->toContain('/apps/gateway/storage/app/orbit/certs')
        ->not->toContain('sudo -iu orbit bash -lc');
});

it('uses local http upstream for wildcard or empty gateway api binds', function (string $bindAddress): void {
    $reflection = new ReflectionClass(E2EGatewayApi::class);
    $method = $reflection->getMethod('tlsServerScript');
    $method->setAccessible(true);

    $script = $method->invoke(null, '/home/orbit/orbit', '10.6.0.2', $bindAddress, 'gateway', [], true);

    expect($script)
        ->toContain("\$httpUpstream = '127.0.0.1';")
        ->toContain('function http_upstream(): string')
        ->toContain("'tcp://'.http_upstream().':80'")
        ->not->toContain('tcp://:80');
})->with([
    'wildcard bind' => '0.0.0.0',
    'empty bind' => '',
]);

it('proxies node grant requests through Laravel api controllers', function (): void {
    $script = gatewayTlsServerScript();
    $apiProxyPosition = strpos($script, "preg_match('#^(GET|POST|PUT|PATCH|DELETE) /api/#', \$requestLine) === 1");
    $legacyNodeGrantPosition = strpos($script, "str_starts_with(\$requestLine, 'POST /api/nodes/grant ')");

    expect($script)
        ->toContain('proxy_to_laravel($connection, $requestLine, $headers, $body);')
        ->and($apiProxyPosition)->toBeInt()
        ->and($legacyNodeGrantPosition)->toBeInt()
        ->and($apiProxyPosition)->toBeLessThan($legacyNodeGrantPosition);
});

it('starts Incus gateway API support from orbit-gateway containers without host PHP', function (): void {
    $instance = new class implements E2EInstance
    {
        /** @var list<string> */
        public array $commands = [];

        public function name(): string
        {
            return 'gateway';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return Process::result();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return Process::result();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.6.0.2';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };

    E2EGatewayApi::start($instance, 'runtime-env', '/home/orbit/orbit-current', '10.6.0.2');

    $setup = implode("\n", $instance->commands);
    $certificateCommand = collect($instance->commands)->first(fn (string $command): bool => str_contains($command, 'orbit-gateway:prepared-current')
        && str_contains($command, 'artisan tinker --execute='));
    $httpStart = collect($instance->commands)->first(fn (string $command): bool => str_contains($command, '--name')
        && str_contains($command, 'orbit-gateway-e2e-runtime-env-http')
        && str_contains($command, 'php -d display_errors=0 -d max_execution_time=0 -S'));
    $tlsStart = collect($instance->commands)->first(fn (string $command): bool => str_contains($command, '--name')
        && str_contains($command, 'orbit-gateway-e2e-runtime-env-tls')
        && str_contains($command, '/tmp/orbit-runtime-env-tls.php'));

    expect($setup)
        ->toContain('docker run --rm --pull never')
        ->toContain('docker run --rm --detach')
        ->toContain('--network host')
        ->toContain('orbit-gateway:prepared-current')
        ->toContain('/home/orbit/.config/orbit')
        ->toContain('type=bind,source=/home/orbit/orbit-current,target=/srv/orbit')
        ->toContain('/home/orbit/orbit-current/apps/cli/orbit:/usr/local/bin/orbit-cli:ro')
        ->not->toContain('/usr/local/bin/orbit:/usr/local/bin/orbit-cli')
        ->toContain('/home/orbit/.ssh:/home/orbit/.ssh')
        ->toContain('ORBIT_E2E_TRUST_WIREGUARD_HEADER=true')
        ->not->toContain('cd /home/orbit/orbit')
        ->not->toContain('php apps/gateway/artisan')
        ->not->toContain('nohup php');

    expect($certificateCommand)->toBeString()
        ->toContain('artisan tinker --execute=');

    expect(gatewayDecodedTinkerPayload((string) $certificateCommand))
        ->toContain('issueLeaf');

    expect($httpStart)->toBeString()
        ->toContain('ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit')
        ->toContain('ORBIT_GATEWAY_URL=http://10.6.0.2')
        ->toContain('ORBIT_FORWARD_INSTALL_BINARY=/usr/local/bin/orbit-cli')
        ->toContain('ORBIT_LOCAL_EXECUTOR_BINARY=/usr/local/bin/orbit-cli')
        ->toContain('PHP_CLI_SERVER_WORKERS=4')
        ->toContain('VIEW_COMPILED_PATH=/srv/orbit/apps/gateway/storage/framework/views')
        ->toContain('type=bind,source=/home/orbit/orbit-current,target=/srv/orbit')
        ->toContain('/home/orbit/orbit-current/apps/cli/orbit:/usr/local/bin/orbit-cli:ro')
        ->not->toContain('/usr/local/bin/orbit:/usr/local/bin/orbit-cli')
        ->toContain('/home/orbit/.wg-easy:/home/orbit/.wg-easy')
        ->toContain('/home/orbit/.ssh:/home/orbit/.ssh')
        ->toContain('/root/.ssh:/root/.ssh')
        ->toContain('/tmp/orbit-runtime-env-http-router.php')
        ->toContain('php -d display_errors=0 -d max_execution_time=0 -S 10.6.0.2:80 -t public /tmp/orbit-runtime-env-http-router.php');

    expect($tlsStart)->toBeString()
        ->toContain('ORBIT_GATEWAY_E2E_CLI=/usr/local/bin/orbit-cli')
        ->toContain('/tmp/orbit-runtime-env-tls.php');
});

it('reads Incus gateway nodes through orbit-gateway image commands', function (): void {
    $instance = new class implements E2EInstance
    {
        /** @var list<string> */
        public array $commands = [];

        public function name(): string
        {
            return 'gateway';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return Process::result(output: json_encode([
                'name' => 'app-dev-1',
                'roles' => [],
            ], JSON_THROW_ON_ERROR));
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return Process::result();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.6.0.2';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };

    $node = E2EGatewayApi::getNode($instance, 'app-dev-1');

    expect($node)->toBe([
        'name' => 'app-dev-1',
        'roles' => [],
    ]);

    $command = $instance->commands[0] ?? '';

    expect($command)
        ->toContain('docker run --rm --pull never')
        ->toContain('orbit-gateway:prepared-current')
        ->toContain('artisan tinker --execute=')
        ->toContain('DB_DATABASE=/home/orbit/.config/orbit/gateway.sqlite')
        ->not->toContain('cd /home/orbit/orbit')
        ->not->toContain('php apps/gateway/artisan');

    expect(gatewayDecodedTinkerPayload($command))
        ->toContain('firstOrFail()');
});

it('starts Docker gateway API support through gateway container commands without host PHP or host Caddy', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result();
    });

    $instance = new DockerInstance(
        new DockerHost(E2EConfig::fromEnvironment()),
        'orbit-e2e-run123-gateway',
        'orbit-e2e-run123',
    );

    E2EGatewayApi::start(
        $instance,
        'docker-gateway-api',
        gatewayIp: '10.6.0.2',
        wireguardIdentity: '10.6.0.2',
        bindAddress: '0.0.0.0',
        certKey: 'gateway',
        certSans: ['10.6.0.2'],
    );

    $setup = implode("\n", $commands);

    $statePrepare = collect($commands)->first(fn (string $command): bool => str_contains($command, "docker exec --user 'orbit' 'orbit-e2e-run123-gateway' sh -lc")
        && str_contains($command, 'php apps/gateway/artisan key:generate --force --no-interaction')
        && str_contains($command, 'php apps/gateway/artisan migrate --force --no-interaction --ansi'));
    $httpStart = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec --detach')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-gateway')
        && str_contains($command, 'php -d display_errors=0 -d max_execution_time=0 -S'));
    $httpRouterWrite = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-gateway')
        && str_contains($command, 'cat >')
        && str_contains($command, '/tmp/orbit-docker-gateway-api-http-router.php'));
    $tlsWrite = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-gateway')
        && str_contains($command, 'cat >')
        && str_contains($command, '/tmp/orbit-docker-gateway-api-tls.php'));
    $tlsStart = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec --detach')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-gateway')
        && str_contains($command, 'php apps/gateway/artisan tinker --execute='));
    $certificateSetup = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec --env')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-gateway')
        && str_contains($command, 'php apps/gateway/artisan key:generate --force --no-interaction')
        && str_contains($command, 'issueLeaf'));
    $runtimeIdentity = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-gateway')
        && str_contains($command, '/home/orbit/.ssh/id_ed25519')
        && str_contains($command, '/root/.ssh/id_ed25519'));

    expect($setup)
        ->toContain('php apps/gateway/artisan tinker --execute=')
        ->toContain('php -d display_errors=0 -d max_execution_time=0 -S')
        ->toContain('sudo docker exec --detach')
        ->toContain("'orbit-e2e-run123-gateway-orbit-gateway'")
        ->toContain('ORBIT_SOURCE_PATH=/home/orbit/orbit')
        ->toContain('ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit')
        ->toContain('/home/orbit/.config/orbit/.env')
        ->toContain('/home/orbit/.config/orbit/gateway.sqlite')
        ->toContain('/home/orbit/orbit/apps/gateway/storage/framework/views')
        ->not->toContain('php artisan')
        ->not->toContain('nohup php')
        ->not->toContain('php -r')
        ->not->toContain('([ -f apps/gateway/.env ] || cp apps/gateway/.env.example apps/gateway/.env)')
        ->not->toContain('/home/orbit/.config/orbit/gateway/storage/framework/views')
        ->not->toContain('systemctl stop caddy');

    $appKey = strpos((string) $statePrepare, 'php apps/gateway/artisan key:generate --force --no-interaction');
    $migrate = strpos((string) $statePrepare, 'php apps/gateway/artisan migrate --force --no-interaction --ansi');

    expect($statePrepare)->toBeString()
        ->and($appKey)->toBeInt()
        ->and($migrate)->toBeInt()
        ->and($appKey)->toBeLessThan($migrate);

    expect($runtimeIdentity)->toBeString()
        ->toContain('install -d -m 700 /root/.ssh')
        ->toContain('cp /home/orbit/.ssh/id_ed25519 /root/.ssh/id_ed25519');

    $normalizedCertificateSetup = preg_replace('/[^A-Za-z0-9_.:\/=>-]+/', '', (string) $certificateSetup);

    expect($certificateSetup)->toBeString()
        ->toContain('/home/orbit/.config/orbit/.env')
        ->toContain('/home/orbit/.config/orbit/gateway.sqlite')
        ->toContain('ORBIT_SOURCE_PATH=/home/orbit/orbit')
        ->toContain('ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit')
        ->toContain('DB_DATABASE=')
        ->toContain('php apps/gateway/artisan migrate --force --no-interaction --ansi')
        ->toContain('Node::query()->updateOrCreate')
        ->toContain('NodeRoleAssignment::query()->updateOrCreate')
        ->toContain('ensureRootCa')
        ->toContain('--workdir')
        ->not->toContain('orbit key:generate')
        ->not->toContain('sudo -iu orbit');

    expect($normalizedCertificateSetup)
        ->toContain('host=>gateway')
        ->toContain('wireguard_address=>10.6.0.2')
        ->not->toContain('wireguard_address=>gateway');

    expect(strpos((string) $certificateSetup, 'php apps/gateway/artisan migrate --force --no-interaction --ansi'))
        ->toBeLessThan(strpos((string) $certificateSetup, 'Node::query()->updateOrCreate'))
        ->and(strpos((string) $certificateSetup, 'NodeRoleAssignment::query()->updateOrCreate'))
        ->toBeLessThan(strpos((string) $certificateSetup, 'ensureRootCa'))
        ->and(strpos((string) $certificateSetup, 'ensureRootCa'))
        ->toBeLessThan(strpos((string) $certificateSetup, 'issueLeaf'));

    expect(array_search($runtimeIdentity, $commands, strict: true))
        ->toBeLessThan(array_search($httpStart, $commands, strict: true))
        ->toBeLessThan(array_search($tlsStart, $commands, strict: true));

    expect($httpStart)->toBeString()
        ->toContain('ORBIT_SOURCE_PATH=/home/orbit/orbit')
        ->toContain('VIEW_COMPILED_PATH=/home/orbit/orbit/apps/gateway/storage/framework/views')
        ->toContain('/tmp/orbit-docker-gateway-api-http-router.php');

    expect($httpRouterWrite)->toBeString()
        ->toContain('/tmp/orbit-docker-gateway-api-http-router.php');

    expect($tlsWrite)->toBeString()
        ->toContain('/tmp/orbit-docker-gateway-api-tls.php');

    expect($tlsStart)->toBeString()
        ->toContain('/tmp/orbit-docker-gateway-api-tls.php')
        ->not->toContain('sudo -iu orbit');
});

it('stops Docker gateway API TLS shim before restarting', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result();
    });

    $instance = new DockerInstance(
        new DockerHost(E2EConfig::fromEnvironment()),
        'orbit-e2e-run123-gateway',
        'orbit-e2e-run123',
    );

    E2EGatewayApi::restart(
        $instance,
        'docker-gateway-api',
        gatewayIp: '10.6.0.2',
        wireguardIdentity: '10.6.0.2',
        bindAddress: '0.0.0.0',
        certKey: 'gateway',
        certSans: ['10.6.0.2'],
    );

    $stop = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-gateway')
        && str_contains($command, '/proc/[0-9]*/cmdline'));
    $tlsStart = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec --detach')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-gateway')
        && str_contains($command, 'php apps/gateway/artisan tinker --execute='));

    expect($stop)->toBeString()
        ->toContain('/tmp/orbit-')
        ->toContain('-tls.php')
        ->toContain('orbit\ serve\ --host=')
        ->not->toContain('php\ *artisan\ serve\ --host=')
        ->toContain('php\ */apps/gateway/artisan\ serve\ --host=');

    expect($tlsStart)->toBeString()
        ->toContain('/tmp/orbit-docker-gateway-api-tls.php');

    expect(array_search($stop, $commands, strict: true))
        ->toBeLessThan(array_search($tlsStart, $commands, strict: true));
});

it('stops Docker gateway API HTTP processes after the runtime entrypoint execs artisan serve', function (): void {
    expect(gatewayStopScriptMatchesCommand(
        'php /home/orbit/orbit/apps/gateway/artisan serve --host=0.0.0.0 --port=80 --tries=1 --no-reload --quiet',
    ))->toBeTrue()
        ->and(gatewayStopScriptMatchesCommand(
            'php /home/orbit/orbit/artisan serve --host=0.0.0.0 --port=80 --tries=1 --no-reload --quiet',
        ))->toBeFalse()
        ->and(gatewayStopScriptMatchesCommand(
            '/usr/local/bin/php -S 0.0.0.0:80 /home/orbit/orbit/apps/gateway/vendor/laravel/framework/src/Illuminate/Foundation/Console/../resources/server.php',
        ))->toBeTrue()
        ->and(gatewayStopScriptMatchesCommand(
            '/usr/local/bin/php -S 0.0.0.0:80 /home/orbit/orbit/vendor/laravel/framework/src/Illuminate/Foundation/Console/../resources/server.php',
        ))->toBeFalse()
        ->and(gatewayStopScriptMatchesCommand(
            '/usr/local/bin/php -S 0.0.0.0:8080 /home/orbit/orbit/apps/gateway/vendor/laravel/framework/src/Illuminate/Foundation/Console/../resources/server.php',
        ))->toBeFalse();
});

it('treats gateway API stop as idempotent when no matching process remains', function (): void {
    $reflection = new ReflectionClass(E2EGatewayApi::class);
    $method = $reflection->getMethod('stopServerShellScript');
    $method->setAccessible(true);

    $script = $method->invoke(null);

    expect($script)->toContain('exit 0');
});

it('can split gateway wireguard identity from bind address and cert key', function (): void {
    $instance = new class implements E2EInstance
    {
        /** @var list<string> */
        public array $commands = [];

        public function name(): string
        {
            return 'gateway';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return Process::result();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return Process::result();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.6.0.2';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };

    E2EGatewayApi::start(
        $instance,
        'dns-alias',
        '/home/orbit/orbit-current',
        gatewayIp: '10.99.0.2',
        wireguardIdentity: '10.6.0.2',
        bindAddress: '0.0.0.0',
        certKey: 'gateway',
        certSans: ['10.6.0.2'],
    );

    $reflection = new ReflectionClass(E2EGatewayApi::class);
    $method = $reflection->getMethod('tlsServerScript');
    $method->setAccessible(true);
    $tlsScript = $method->invoke(null, '/srv/orbit', '10.6.0.2', '0.0.0.0', 'gateway', [], true, '/usr/local/bin/orbit-cli');
    $certificateCommand = collect($instance->commands)
        ->first(fn (string $command): bool => str_contains($command, 'artisan tinker --execute='));

    expect(gatewayDecodedTinkerPayload((string) $certificateCommand))
        ->toContain('issueLeaf')
        ->toContain('gateway')
        ->toContain('10.6.0.2');

    expect(implode("\n", $instance->commands))
        ->toContain('php -d display_errors=0 -d max_execution_time=0 -S 0.0.0.0:80');

    expect($tlsScript)
        ->toContain('$certKey = \'gateway\'')
        ->toContain("\$certDirectory = '/home/orbit/.config/orbit/certs';")
        ->toContain('$wireguardIdentity = \'10.6.0.2\'')
        ->toContain('$bindAddress = \'0.0.0.0\'')
        ->toContain("stream_socket_server('tls://'.\$bindAddress.':443'")
        ->toContain("'wireguard' => \$wireguardIdentity");
});

it('starts docker gateway api through orbit-gateway without host php-fpm or caddy', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result();
    });

    $instance = new DockerInstance(
        new DockerHost(E2EConfig::fromEnvironment()),
        'orbit-e2e-run123-gateway',
        'orbit-e2e-run123',
    );

    E2EGatewayApi::start(
        $instance,
        'docker-gateway-api',
        gatewayIp: '10.6.0.2',
        wireguardIdentity: '10.6.0.2',
        bindAddress: '0.0.0.0',
        certKey: 'gateway',
        certSans: ['10.6.0.2'],
    );

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain('php -d display_errors=0 -d max_execution_time=0 -S')
        ->toContain('sudo docker exec --detach')
        ->toContain("'orbit-e2e-run123-gateway-orbit-gateway'")
        ->toContain('ORBIT_SOURCE_PATH=/home/orbit/orbit')
        ->not->toContain('php artisan')
        ->not->toContain('php-fpm')
        ->not->toContain('php8.5-fpm')
        ->not->toContain('fpm-fcgi');
});

it('maps configured docker peer ips to canonical wireguard identities in the gateway tls proxy', function (): void {
    $script = gatewayTlsServerScript(peerIdentityMap: [
        '10.61.42.3' => '10.6.0.3',
        '10.61.42.4' => '10.6.0.4',
    ]);

    expect(gatewayCanonicalPeerIp($script, '10.61.42.4'))->toBe('10.6.0.4')
        ->and($script)->toContain('$identity = canonical_peer_ip($clientIp);')
        ->and($script)->toContain("\$headers['x-orbit-e2e-wireguard-ip'] = \$identity;")
        ->and($script)->not->toContain("isset(\$headers['x-orbit-e2e-wireguard-ip'])");
});

it('maps run-scoped docker peer ips to canonical wireguard identities in the gateway tls proxy', function (): void {
    expect(gatewayCanonicalPeerIp(gatewayTlsServerScript(), '10.24.0.3'))->toBe('10.6.0.3')
        ->and(gatewayCanonicalPeerIp(gatewayTlsServerScript(), '10.31.0.4'))->toBe('10.6.0.4');
});

it('adds canonical e2e identity headers in the gateway http router', function (): void {
    $script = gatewayHttpRouterScript(peerIdentityMap: [
        '10.61.42.3' => '10.6.0.3',
    ]);

    expect(gatewayHttpRouterPeerIdentity($script, '10.61.42.3'))->toBe('10.6.0.3')
        ->and(gatewayHttpRouterPeerIdentity($script, '::ffff:10.24.0.4'))->toBe('10.6.0.4')
        ->and(gatewayHttpRouterPeerIdentity($script, '127.0.0.1', '10.6.0.5'))->toBe('10.6.0.5')
        ->and($script)->toContain("! isset(\$_SERVER['HTTP_X_ORBIT_E2E_WIREGUARD_IP'])")
        ->and($script)->toContain("\$_SERVER['HTTP_X_ORBIT_E2E_WIREGUARD_IP'] = \$identity;");
});

it('maps ipv4-mapped docker peer ips to canonical wireguard identities in the gateway tls proxy', function (): void {
    $script = gatewayTlsServerScript(peerIdentityMap: [
        '10.61.42.3' => '10.6.0.3',
    ]);

    expect(gatewayCanonicalPeerIp($script, '::ffff:10.61.42.3'))->toBe('10.6.0.3')
        ->and($script)->toContain('function normalize_peer_ip(string $peerIp): string');
});

it('forwards raw peer ips in the default gateway tls proxy mode', function (): void {
    expect(gatewayCanonicalPeerIp(gatewayTlsServerScript(), '10.61.42.4'))->toBe('10.61.42.4');
});

it('keeps the gateway tls shim listening after transient accept failures', function (): void {
    $script = gatewayTlsServerScript();

    expect($script)
        ->toContain('while (true) {')
        ->toContain('$connection = @stream_socket_accept($server, -1);')
        ->toContain('if ($connection === false) {')
        ->toContain('continue;');
});

/**
 * @param  array<string, string>  $peerIdentityMap
 */
function gatewayTlsServerScript(array $peerIdentityMap = []): string
{
    $reflection = new ReflectionClass(E2EGatewayApi::class);
    $method = $reflection->getMethod('tlsServerScript');
    $method->setAccessible(true);

    return $method->invoke(
        null,
        '/home/orbit/orbit-current',
        '10.6.0.2',
        '0.0.0.0',
        'gateway',
        $peerIdentityMap,
    );
}

function gatewayCanonicalPeerIp(string $script, string $peerIp): string
{
    $prefix = strstr($script, 'function proxy_to_laravel', before_needle: true);

    if (! is_string($prefix)) {
        throw new RuntimeException('Generated gateway TLS script is missing proxy_to_laravel().');
    }

    $file = tempnam(sys_get_temp_dir(), 'orbit-gateway-script-');

    if (! is_string($file)) {
        throw new RuntimeException('Could not create temporary PHP script.');
    }

    file_put_contents($file, $prefix."\n\necho canonical_peer_ip(\$argv[1]);\n");

    try {
        $output = [];
        $exitCode = 0;

        exec('php '.escapeshellarg($file).' '.escapeshellarg($peerIp), $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Generated gateway TLS identity script exited with code '.$exitCode.'.');
        }

        return implode("\n", $output);
    } finally {
        @unlink($file);
    }
}

/**
 * @param  array<string, string>  $peerIdentityMap
 */
function gatewayHttpRouterScript(array $peerIdentityMap = []): string
{
    $reflection = new ReflectionClass(E2EGatewayApi::class);
    $method = $reflection->getMethod('httpRouterScript');
    $method->setAccessible(true);

    return $method->invoke(null, '/home/orbit/orbit-current', $peerIdentityMap);
}

function gatewayHttpRouterPeerIdentity(string $script, string $peerIp, ?string $existingIdentity = null): string
{
    $prefix = strstr($script, '$publicPath =', before_needle: true);

    if (! is_string($prefix)) {
        throw new RuntimeException('Generated gateway HTTP router script is missing the public path bootstrap.');
    }

    $body = preg_replace('/^<\?php\s*/', '', $prefix);

    if (! is_string($body)) {
        throw new RuntimeException('Could not prepare generated gateway HTTP router script.');
    }

    $file = tempnam(sys_get_temp_dir(), 'orbit-gateway-http-router-');

    if (! is_string($file)) {
        throw new RuntimeException('Could not create temporary PHP script.');
    }

    $server = [
        'REMOTE_ADDR' => $peerIp,
    ];

    if ($existingIdentity !== null) {
        $server['HTTP_X_ORBIT_E2E_WIREGUARD_IP'] = $existingIdentity;
    }

    file_put_contents($file, "<?php\n\$_SERVER = ".var_export($server, true).";\n{$body}\necho \$_SERVER['HTTP_X_ORBIT_E2E_WIREGUARD_IP'] ?? '';\n");

    try {
        $output = [];
        $exitCode = 0;
        exec(PHP_BINARY.' '.escapeshellarg($file), $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Generated gateway HTTP router script failed.');
        }

        return trim(implode("\n", $output));
    } finally {
        @unlink($file);
    }
}

function gatewayDecodedTinkerPayload(string $command): string
{
    preg_match('/base64_decode\\([^A-Za-z0-9+\\/=]*(?<payload>[A-Za-z0-9+\\/=]{20,})/', $command, $matches);

    if (! is_string($matches['payload'] ?? null)) {
        throw new RuntimeException('Could not find generated tinker payload.');
    }

    $decoded = base64_decode($matches['payload'], strict: true);

    if (! is_string($decoded)) {
        throw new RuntimeException('Could not decode generated tinker payload.');
    }

    return $decoded;
}

function gatewayStopScriptMatchesCommand(string $command): bool
{
    $reflection = new ReflectionClass(E2EGatewayApi::class);
    $method = $reflection->getMethod('stopServerShellScript');
    $method->setAccessible(true);
    $script = $method->invoke(null);

    preg_match('/case "\$command" in\s+(?<patterns>.+?)\)\s+pids=/s', $script, $matches);

    if (! isset($matches['patterns'])) {
        throw new RuntimeException('Gateway stop script is missing its process matcher.');
    }

    $file = tempnam(sys_get_temp_dir(), 'orbit-gateway-stop-');

    if (! is_string($file)) {
        throw new RuntimeException('Could not create temporary shell script.');
    }

    file_put_contents($file, implode("\n", [
        '#!/usr/bin/env bash',
        'command='.escapeshellarg($command),
        'case "$command" in',
        trim($matches['patterns']).') echo match ;;',
        '*) echo miss ;;',
        'esac',
    ]));

    try {
        $output = [];
        $exitCode = 0;

        exec('bash '.escapeshellarg($file), $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Gateway stop matcher shell check exited with code '.$exitCode.'.');
        }

        return trim(implode("\n", $output)) === 'match';
    } finally {
        @unlink($file);
    }
}
