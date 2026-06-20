<?php

declare(strict_types=1);

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerInstance;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ECurrentCheckout;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\SourceMountedCheckoutInstance;
use App\E2E\Support\SourceMountedCheckoutSyncer;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery as m;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

afterEach(function (): void {
    E2ECurrentCheckout::flushCache();
    E2ECurrentCheckout::useNowResolverForTests(null);
    E2ECurrentCheckout::useInstallerForTests(null);
    m::close();
});

function currentCheckoutProcessResult(bool $successful = true): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn('');
    $result->shouldReceive('errorOutput')->andReturn('');

    return $result;
}

function currentCheckoutFakeInstance(array &$commands, string $name = 'fake-operator', ?array &$timeouts = null): E2EInstance
{
    return new class($commands, $name, $timeouts) implements E2EInstance
    {
        /**
         * @param  array<int, string>  $commands
         * @param  array<int, int|null>|null  $timeouts
         */
        public function __construct(
            private array &$commands,
            private readonly string $name,
            private ?array &$timeouts = null,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;
            $this->timeouts[] = $timeoutSeconds;

            return currentCheckoutProcessResult();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;
            $this->timeouts[] = $timeoutSeconds;

            return currentCheckoutProcessResult();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void
        {
            $this->commands[] = "copy {$sourcePath} {$targetPath}";
        }

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.201.0.10';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };
}

function currentCheckoutFakeSourceMountedInstance(array &$commands, string $name = 'fake-operator', ?array &$timeouts = null): E2EInstance
{
    return new class($commands, $name, $timeouts) implements E2EInstance, SourceMountedCheckoutInstance
    {
        /**
         * @param  array<int, string>  $commands
         * @param  array<int, int|null>|null  $timeouts
         */
        public function __construct(
            private array &$commands,
            private readonly string $name,
            private ?array &$timeouts = null,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function sourceMountedCheckoutPath(): ?string
        {
            return '/home/orbit/orbit';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;
            $this->timeouts[] = $timeoutSeconds;

            return currentCheckoutProcessResult();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;
            $this->timeouts[] = $timeoutSeconds;

            return currentCheckoutProcessResult();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void
        {
            $this->commands[] = "copy {$sourcePath} {$targetPath}";
        }

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.201.0.10';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };
}

it('reuses prepared vendor packages while rebuilding checkout local autoload files', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'orbit', $key);

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)->toContain("cmp -s '/home/orbit/orbit/apps/gateway/composer.lock' apps/gateway/composer.lock")
        ->and($commandOutput)->toContain("[ -d '/home/orbit/orbit/apps/gateway/vendor/composer' ]")
        ->and($commandOutput)->toContain("rm -rf 'apps/gateway/vendor'")
        ->and($commandOutput)->toContain("find '/home/orbit/orbit/apps/gateway/vendor' -mindepth 1 -maxdepth 1 ! -name composer ! -name autoload.php -exec sh -c")
        ->and($commandOutput)->toContain('cp -al "$path" "$target"/')
        ->and($commandOutput)->toContain('cp -a --reflink=always "$path" "$target"/')
        ->and($commandOutput)->toContain('cp -a "$path" "$target"/')
        ->and($commandOutput)->toContain("cp -a '/home/orbit/orbit/apps/gateway/vendor/composer' 'apps/gateway/vendor'/composer")
        ->and($commandOutput)->toContain("cp '/home/orbit/orbit/apps/gateway/vendor/autoload.php' 'apps/gateway/vendor'/autoload.php")
        ->and($commandOutput)->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->and($commandOutput)->toContain(SourceMountedCheckoutSyncer::vendorArchiveRelativePath('apps/gateway'))
        ->and($commandOutput)->toContain("tar --warning=no-unknown-keyword -C 'apps/gateway' -xf")
        ->and($commandOutput)->toContain("cmp -s '/home/orbit/orbit/apps/cli/composer.lock' apps/cli/composer.lock")
        ->and($commandOutput)->toContain("[ -d '/home/orbit/orbit/apps/cli/vendor/composer' ]")
        ->and($commandOutput)->toContain("rm -rf 'apps/cli/vendor'")
        ->and($commandOutput)->toContain("find '/home/orbit/orbit/apps/cli/vendor' -mindepth 1 -maxdepth 1 ! -name composer ! -name autoload.php -exec sh -c")
        ->and($commandOutput)->toContain("cp -a '/home/orbit/orbit/apps/cli/vendor/composer' 'apps/cli/vendor'/composer")
        ->and($commandOutput)->toContain("cp '/home/orbit/orbit/apps/cli/vendor/autoload.php' 'apps/cli/vendor'/autoload.php")
        ->and($commandOutput)->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->and($commandOutput)->toContain(SourceMountedCheckoutSyncer::vendorArchiveRelativePath('apps/cli'))
        ->and($commandOutput)->toContain("tar --warning=no-unknown-keyword -C 'apps/cli' -xf")
        ->and($commandOutput)->not->toContain("ln -s '/home/orbit/orbit/vendor' vendor")
        ->and($commandOutput)->not->toContain('-exec ln -s')
        ->and($commandOutput)->toContain('elif command -v composer >/dev/null 2>&1; then composer --working-dir=apps/gateway install --no-interaction --prefer-dist --optimize-autoloader')
        ->and($commandOutput)->toContain('Gateway Composer dependencies are not installed and prepared vendor dependencies could not be reused.')
        ->and($commandOutput)->toContain('elif command -v composer >/dev/null 2>&1; then composer --working-dir=apps/cli install --no-interaction --prefer-dist --optimize-autoloader')
        ->and($commandOutput)->toContain('CLI Composer dependencies are not installed and prepared vendor dependencies could not be reused.');
});

it('skips cli env copies when docker host-launcher vendor reuse points at the mounted checkout', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'reuseRuntimeDependenciesCommand');
    $method->setAccessible(true);

    $command = $method->invoke(null, '/home/orbit/orbit', '/home/orbit/orbit', null, false);

    expect($command)
        ->toContain('apps/gateway/vendor/autoload.php')
        ->toContain('apps/cli/vendor/autoload.php')
        ->not->toContain("cp '/home/orbit/orbit/apps/cli/.env' apps/cli/.env");
});

it('runs source-mounted Incus checkouts from a VM-local overlay runtime path', function (): void {
    $commands = [];
    $instance = currentCheckoutFakeSourceMountedInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $timer = new E2EPhaseTimer;

    $checkout = E2ECurrentCheckout::install($instance, 'orbit', $key, hostLauncher: true, timer: $timer);

    $commandOutput = implode("\n", $commands);

    expect($checkout)->toBe('/home/orbit/orbit-run')
        ->and($commandOutput)
        ->toContain("source='/home/orbit/orbit'")
        ->toContain("target='/home/orbit/orbit-run'")
        ->toContain("upper='/home/orbit/.orbit-run-overlay/upper'")
        ->toContain("work='/home/orbit/.orbit-run-overlay/work'")
        ->toContain('mount -t overlay overlay')
        ->toContain('$sudo_prefix rm -rf "$target" "$upper" "$work"')
        ->not->toContain('tar -C "${target}" -xf -')
        ->toContain('.orbit-e2e-vendor-archives/apps-gateway-vendor.tar')
        ->toContain('.orbit-e2e-vendor-archives/apps-cli-vendor.tar')
        ->toContain("install -d -m 0700 -o orbit -g orbit '/home/orbit/.config/orbit'")
        ->toContain("sudo ln -sfn '/home/orbit/orbit-run/apps/cli/orbit' '/usr/local/bin/orbit'")
        ->not->toContain("sudo ln -sfn '/home/orbit/orbit/apps/cli/orbit' '/usr/local/bin/orbit'")
        ->not->toContain('/tmp/orbit-current.tar.gz')
        ->not->toContain('tar --warning=no-unknown-keyword -xzf')
        ->and(array_column($timer->events(), 'name'))->toContain('checkout.source-overlay');
});

it('refreshes source-mounted Incus gateway settings and CLI trust config', function (): void {
    $operatorCommands = [];
    $gatewayCommands = [];
    $devCommands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdev,
        operator: currentCheckoutFakeSourceMountedInstance($operatorCommands, 'operator'),
        gateway: currentCheckoutFakeSourceMountedInstance($gatewayCommands, 'gateway'),
        dev: currentCheckoutFakeSourceMountedInstance($devCommands, 'dev'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );

    E2ECurrentCheckout::installOnTopology($topology, roles: ['operator', 'gateway', 'dev']);

    expect(implode("\n", $operatorCommands))
        ->toContain('LocalGatewaySettings::current()')
        ->toContain('https://10.6.0.2')
        ->toContain('http://{$gatewayIp}/api/ca/root')
        ->toContain('/.config/orbit')
        ->toContain('/gateways/default')
        ->toContain('/config.json')
        ->toContain('/ca.pem')
        ->toContain('wireguard_https')
        ->toContain('e2e-current-checkout')
        ->not->toContain('gateway:add')
        ->and(implode("\n", $gatewayCommands))
        ->toContain('LocalGatewaySettings::current()')
        ->toContain('https://10.6.0.2')
        ->toContain('http://{$gatewayIp}/api/ca/root')
        ->toContain('/.config/orbit')
        ->toContain('/gateways/default')
        ->toContain('/config.json')
        ->toContain('/ca.pem')
        ->toContain('wireguard_https')
        ->toContain('e2e-current-checkout')
        ->not->toContain('gateway:add')
        ->and(implode("\n", $devCommands))
        ->toContain('LocalGatewaySettings::current()')
        ->toContain('https://10.6.0.2')
        ->toContain('http://{$gatewayIp}/api/ca/root')
        ->toContain('/.config/orbit')
        ->toContain('/gateways/default')
        ->toContain('/config.json')
        ->toContain('/ca.pem')
        ->toContain('wireguard_https')
        ->toContain('e2e-current-checkout')
        ->not->toContain('gateway:add');
});

it('writes source-mounted gateway state under the node config root instead of the mounted source tree', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'runtimeStateCommand');
    $method->setAccessible(true);

    $command = $method->invoke(
        null,
        '/home/orbit/orbit',
        '/home/orbit/orbit',
        false,
        null,
        false,
        true,
    );

    $removeTmpPosition = strpos($command, "rm -f '/home/orbit/.config/orbit/.env.tmp'");
    $rewritePosition = strpos($command, "grep -Ev '^(DB_DATABASE|SESSION_DRIVER)='");

    expect($command)
        ->toContain("sudo install -d -m 775 -o orbit -g orbit '/home/orbit/.config/orbit'")
        ->toContain("sudo chown -R orbit:orbit '/home/orbit/.config/orbit'")
        ->toContain('/home/orbit/.config/orbit/.env')
        ->toContain('/home/orbit/.config/orbit/.env.tmp')
        ->toContain('/home/orbit/.config/orbit/gateway.sqlite')
        ->toContain('/home/orbit/.config/orbit')
        ->not->toContain("cp '/home/orbit/orbit/apps/gateway/.env' apps/gateway/.env")
        ->not->toContain('apps/gateway/database/database.sqlite')
        ->not->toContain('apps/gateway/storage/app');

    expect($removeTmpPosition)->toBeInt()
        ->and($rewritePosition)->toBeInt()
        ->and($removeTmpPosition)->toBeLessThan($rewritePosition);
});

it('keeps gateway state in the node config root for regular checkouts', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'runtimeStateCommand');
    $method->setAccessible(true);

    $command = $method->invoke(
        null,
        '/home/orbit/orbit',
        null,
        false,
        null,
        false,
        false,
    );

    expect($command)
        ->toContain('/home/orbit/.config/orbit/.env')
        ->toContain('/home/orbit/.config/orbit/gateway.sqlite')
        ->not->toContain('apps/gateway/database/database.sqlite')
        ->not->toContain('ORBIT_CONFIG_ROOT=');
});

it('passes Docker gateway state environment through the current checkout wrapper', function (): void {
    $script = E2ECurrentCheckout::orbitWrapperScript('/home/orbit/orbit-current', dockerRuntime: true);

    expect($script)
        ->toContain("--env 'ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit'")
        ->toContain("--env 'DB_CONNECTION=sqlite'")
        ->toContain("--env 'DB_DATABASE=/home/orbit/.config/orbit/gateway.sqlite'")
        ->toContain("--env 'SESSION_DRIVER=file'");
});

it('does not seed mutable gateway state from prepared checkout source paths', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)
        ->toContain('/home/orbit/.config/orbit/.env')
        ->toContain('/home/orbit/.config/orbit/gateway.sqlite')
        ->not->toContain("cp '/home/orbit/orbit/apps/gateway/.env' apps/gateway/.env")
        ->not->toContain("cp '/home/orbit/orbit/apps/gateway/database/database.sqlite' apps/gateway/database/database.sqlite")
        ->not->toContain("cp -a '/home/orbit/orbit/apps/gateway/storage/app' apps/gateway/storage/app");
});

it('records checkout phase timings while installing the current checkout', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $timer = new E2EPhaseTimer;

    E2ECurrentCheckout::install($instance, 'orbit', $key, timer: $timer);

    expect(array_column($timer->events(), 'name'))->toBe([
        'checkout.archive',
        'checkout.copy',
        'checkout.extract',
        'checkout.vendor',
        'checkout.runtime-state',
        'checkout.migrate',
    ]);
});

it('does not record archive timing for shared archive cache hits', function (): void {
    $previousCache = getenv('ORBIT_E2E_CHECKOUT_CACHE');
    $previousCacheDir = getenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
    $cacheDir = sys_get_temp_dir().'/orbit-checkout-archive-hit-test-'.bin2hex(random_bytes(4));
    $tarBuilds = 0;

    putenv('ORBIT_E2E_CHECKOUT_CACHE=process');
    putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$cacheDir}");

    Process::fake(function ($process) use (&$tarBuilds) {
        if (str_starts_with((string) $process->command, 'COPYFILE_DISABLE=1 tar ')) {
            $tarBuilds++;

            if (preg_match("/ -czf '([^']+)' /", (string) $process->command, $matches) === 1) {
                file_put_contents($matches[1], 'archive');
            }
        }

        return Process::result();
    });

    $operatorCommands = [];
    $gatewayCommands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $timer = new E2EPhaseTimer;

    try {
        E2ECurrentCheckout::install(currentCheckoutFakeInstance($operatorCommands, 'operator'), 'orbit', $key, seedFrom: '/home/orbit/orbit', timer: $timer);
        E2ECurrentCheckout::install(currentCheckoutFakeInstance($gatewayCommands, 'gateway'), 'orbit', $key, seedFrom: '/home/orbit/orbit', timer: $timer);

        expect($tarBuilds)->toBe(1)
            ->and(array_filter(array_column($timer->events(), 'name'), fn (string $name): bool => $name === 'checkout.archive'))->toHaveCount(1);
    } finally {
        E2ECurrentCheckout::flushCache();
        Process::run('rm -rf '.escapeshellarg($cacheDir));

        if ($previousCache === false) {
            putenv('ORBIT_E2E_CHECKOUT_CACHE');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_CACHE={$previousCache}");
        }

        if ($previousCacheDir === false) {
            putenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$previousCacheDir}");
        }
    }
});

it('makes the checkout archive readable before copying it into an instance', function (): void {
    Process::fake(function ($process) {
        if (preg_match("/ -czf '([^']+)' /", (string) $process->command, $matches) === 1) {
            file_put_contents($matches[1], 'archive');
            chmod($matches[1], 0600);
        }

        return Process::result();
    });

    $copiedMode = null;
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $instance = new class($copiedMode) implements E2EInstance
    {
        public function __construct(private ?string &$copiedMode) {}

        public function name(): string
        {
            return 'fake-operator';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return currentCheckoutProcessResult();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return currentCheckoutProcessResult();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void
        {
            $this->copiedMode = decoct(fileperms($sourcePath) & 0777);
        }

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.201.0.10';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };

    E2ECurrentCheckout::install($instance, 'orbit', $key);

    expect($copiedMode)->toBe('644');
});

it('excludes persisted orbit certificate material from checkout archive manifests', function (): void {
    $certificatePath = repo_path('apps/gateway/storage/app/orbit/ca/e2e-test-certificate.pem');
    $manifestEntries = [];

    if (! is_dir(dirname($certificatePath))) {
        mkdir(dirname($certificatePath), 0777, true);
    }

    file_put_contents($certificatePath, 'secret');

    Process::fake(function ($process) use (&$manifestEntries) {
        if (str_starts_with((string) $process->command, 'COPYFILE_DISABLE=1 tar ')) {
            if (preg_match("/ -T '([^']+)'/", (string) $process->command, $matches) === 1) {
                $manifestEntries = array_values(array_filter(
                    explode("\0", (string) file_get_contents($matches[1])),
                    fn (string $path): bool => $path !== '',
                ));
            }
        }

        return Process::result();
    });

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    try {
        E2ECurrentCheckout::install($instance, 'orbit', $key);

        expect($manifestEntries)->not->toContain('apps/gateway/storage/app/orbit/ca/e2e-test-certificate.pem');
    } finally {
        @unlink($certificatePath);
    }
});

it('builds checkout archives from tracked and unignored files only', function (): void {
    $suffix = bin2hex(random_bytes(4));
    $includedPath = repo_path("tmp-e2e-archive-include-{$suffix}.txt");
    $ignoredPath = repo_path("tmp-e2e-archive-include-{$suffix}.log");
    $manifestEntries = [];

    file_put_contents($includedPath, 'included');
    file_put_contents($ignoredPath, 'ignored');

    Process::fake(function ($process) use (&$manifestEntries) {
        if (str_starts_with((string) $process->command, 'COPYFILE_DISABLE=1 tar ')) {
            if (preg_match("/ -T '([^']+)'/", (string) $process->command, $matches) === 1) {
                $manifestEntries = array_values(array_filter(
                    explode("\0", (string) file_get_contents($matches[1])),
                    fn (string $path): bool => $path !== '',
                ));
            }
        }

        return Process::result();
    });

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    try {
        E2ECurrentCheckout::install($instance, 'orbit', $key);

        expect($manifestEntries)
            ->toContain(basename($includedPath))
            ->toContain('apps/gateway/.env.example')
            ->not->toContain(basename($ignoredPath));
    } finally {
        @unlink($includedPath);
        @unlink($ignoredPath);
    }
});

it('publishes the checkout archive excludes for tarball construction', function (): void {
    expect(E2ECurrentCheckout::archiveExcludePatterns())
        ->toContain(
            './.git',
            './.worktrees',
            './.orbit-e2e-vendor-archives',
            './.env',
            './build',
            './tmp-e2e-archive-manifest-*.txt',
            './apps/gateway/.env',
            './apps/cli/.env',
            './apps/cli/.env.e2e',
            './apps/cli/.env.local',
            './apps/gateway/public/build',
            './apps/gateway/public/hot',
            './apps/gateway/public/storage',
            './apps/gateway/storage/app/orbit/ca/*',
            './apps/gateway/storage/app/orbit/certs/*',
            './apps/gateway/storage/app/orbit/keys/*',
            './apps/gateway/storage/framework/e2e/*',
            './apps/gateway/database/*.sqlite',
            './apps/gateway/database/*.sqlite-*',
            './apps/gateway/tests/E2E/.docker-feature-tests/*',
            './apps/gateway/tests/E2E/.incus-feature-tests/*',
            './apps/gateway/vendor',
        );
});

it('includes untracked path identity in the checkout tree hash', function (): void {
    $directory = repo_path('tmp-e2e-tree-hash-'.bin2hex(random_bytes(4)));

    mkdir($directory, 0777, true);
    file_put_contents("{$directory}/alpha.txt", 'same-content');
    file_put_contents("{$directory}/beta.txt", 'same-content');

    try {
        $hashWithTwoPaths = E2ECurrentCheckout::treeHash();

        unlink("{$directory}/beta.txt");

        $hashAfterRemovingPath = E2ECurrentCheckout::treeHash();

        expect($hashAfterRemovingPath)->not->toBe($hashWithTwoPaths);
    } finally {
        @unlink("{$directory}/alpha.txt");
        @unlink("{$directory}/beta.txt");
        @rmdir($directory);
    }
});

it('runs the runtime-state phase from inside the remote checkout directory', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

    expect($commands[3])->toStartWith("cd '/home/orbit/orbit-current' && ")
        ->and($commands[3])->toContain('/home/orbit/.config/orbit/.env')
        ->and($commands[3])->toContain('/home/orbit/.config/orbit/gateway.sqlite');
});

it('marks Docker topology checkout env files with the Docker provider', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run123-operator', 'orbit-e2e-run123');
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

    $nodeInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-operator'"),
    )));

    $removeTmpPosition = strpos($nodeInstallCommands, 'rm -f');
    $providerRewritePosition = strpos($nodeInstallCommands, 'grep -Ev');

    expect($nodeInstallCommands)
        ->toContain('ORBIT_E2E_TOPOLOGY_PROVIDER')
        ->toContain('ORBIT_E2E_TOPOLOGY_PROVIDER=docker')
        ->toContain('/home/orbit/.config/orbit/.env.tmp')
        ->and($removeTmpPosition)->toBeInt()
        ->and($providerRewritePosition)->toBeInt()
        ->and($removeTmpPosition)->toBeLessThan($providerRewritePosition);
});

it('keeps Docker seeded gateway state in the node local config root', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run123-operator', 'orbit-e2e-run123');
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/srv/prepared/orbit');

    $nodeInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-operator'"),
    )));

    expect($nodeInstallCommands)
        ->toContain('/home/orbit/.config/orbit/.env')
        ->toContain('/home/orbit/.config/orbit/gateway.sqlite')
        ->toContain('/home/orbit/.config/orbit')
        ->not->toContain('/srv/prepared/.config/orbit')
        ->not->toContain("cp '/srv/prepared/orbit/apps/gateway/.env' apps/gateway/.env")
        ->not->toContain('rm -f apps/gateway/database/database.sqlite apps/gateway/database/database.sqlite-*')
        ->not->toContain('rm -rf apps/gateway/storage/app');
});

it('regenerates empty app keys while preparing remote checkout env files', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

    expect($commands[3])
        ->toContain("grep -q '^APP_KEY=' '/home/orbit/.config/orbit/.env' || printf '%s\\n' 'APP_KEY=' >> '/home/orbit/.config/orbit/.env'")
        ->toContain("grep -Eq '^APP_KEY=base64:.+' '/home/orbit/.config/orbit/.env' || php apps/gateway/artisan key:generate --force --no-interaction --ansi")
        ->toContain("grep -Eq '^APP_KEY=base64:.+' '/home/orbit/.config/orbit/.env'");
});

it('installs the current checkout on Docker topology nodes through the runtime container', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run123-operator', 'orbit-e2e-run123');
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

    $nodeInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-operator'"),
    )));
    $wrapperCopyCommands = array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, 'docker cp ')
            && str_contains($command, "'orbit-e2e-run123-operator:/usr/local/bin/orbit'"),
    ));

    expect($nodeInstallCommands)
        ->toContain('sudo docker exec --env')
        ->toContain('orbit-e2e-run123-operator-orbit-gateway')
        ->toContain('key:generate --force --no-interaction --ansi')
        ->toContain('/home/orbit/orbit-current/apps/gateway/artisan')
        ->toContain('/home/orbit/.config/orbit/.env')
        ->toContain('/home/orbit/.config/orbit')
        ->toContain('migrate --force --ansi')
        ->toContain('/home/orbit/orbit/apps/cli/vendor')
        ->toContain('cp -al "$path" "$target"/')
        ->toContain('cp -a --reflink=always')
        ->toContain('cp -a "$path" "$target"/')
        ->not->toContain('-exec ln -s')
        ->toContain('COMPOSER_CACHE_DIR=/tmp/orbit-composer-cache')
        ->toContain('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress')
        ->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->toContain('/home/orbit/orbit/apps/cli/.env')
        ->toContain('apps/cli/.env')
        ->toContain('LocalGatewaySettings::current()')
        ->toContain('http://gateway/api/ca/root')
        ->toContain('https://gateway')
        ->toContain('ca_sha256')
        ->toContain('ca_pem_path')
        ->not->toContain('orbit list --raw 2>/dev/null | grep -qx')
        ->not->toContain('php artisan')
        ->not->toContain('nohup php')
        ->not->toContain('php -S')
        ->and($wrapperCopyCommands)->toHaveCount(1);
});

it('bootstraps gateway app state for Docker host launcher checkout nodes through an archive checkout', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run123-dev', 'orbit-e2e-run123');
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install(
        $instance,
        'orbit',
        $key,
        seedFrom: '/home/orbit/orbit',
        executorNodeIdentity: 'app-dev-1',
        hostLauncher: true,
    );

    $nodeInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-dev'"),
    )));
    $allCommands = implode("\n", $commands);

    expect($nodeInstallCommands)
        ->toContain("tar --warning=no-unknown-keyword -xzf /tmp/orbit-current.tar.gz -C '\\''/home/orbit/orbit-current'\\''")
        ->toContain('apps/gateway/vendor/autoload.php')
        ->toContain('Prepared gateway vendor dependencies are required for Docker host-launcher checkout')
        ->toContain('php apps/gateway/artisan key:generate --force --no-interaction --ansi')
        ->toContain('php apps/gateway/artisan migrate --force --ansi')
        ->toContain('php apps/gateway/artisan tinker --execute')
        ->toContain('LocalGatewaySettings::current()')
        ->toContain('/home/orbit/.config/orbit/.env')
        ->toContain('apps/cli/vendor/autoload.php')
        ->toContain('Prepared CLI vendor dependencies are required for Docker current checkout')
        ->toContain('rm -rf')
        ->toContain('apps/gateway/vendor')
        ->toContain('apps/cli/vendor')
        ->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->not->toContain('ln -sfn /home/orbit/.config/orbit apps/gateway/storage')
        ->not->toContain('ln -sfn /home/orbit/.config/orbit/.env apps/gateway/.env')
        ->not->toContain('ln -sfn /home/orbit/.config/orbit/gateway.sqlite apps/gateway/database/database.sqlite')
        ->toContain('apps/cli/.env')
        ->toContain("cp '\\''/home/orbit/orbit/apps/cli/.env'\\'' apps/cli/.env")
        ->not->toContain('orbit-e2e-run123-dev-orbit-gateway')
        ->not->toContain('sudo docker exec --env')
        ->not->toContain('composer install')
        ->and($allCommands)->toContain('tar --warning=no-unknown-keyword -xzf /tmp/orbit-current.tar.gz -C')
        ->and($allCommands)->toContain('/home/orbit/orbit-current/apps/cli/orbit')
        ->and($allCommands)->toContain('/usr/local/bin/orbit');
});

it('uses the host launcher for Docker operator gateway and app-node checkouts', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $host = new DockerHost(E2EConfig::fromEnvironment());
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdev,
        operator: new DockerInstance($host, 'orbit-e2e-run123-operator', 'orbit-e2e-run123'),
        gateway: new DockerInstance($host, 'orbit-e2e-run123-gateway', 'orbit-e2e-run123'),
        dev: new DockerInstance($host, 'orbit-e2e-run123-dev', 'orbit-e2e-run123'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );

    $paths = E2ECurrentCheckout::installOnTopology($topology, roles: ['operator', 'gateway', 'dev']);

    $operatorInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-operator'"),
    )));
    $gatewayInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-gateway'"),
    )));
    $devInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-dev'"),
    )));

    expect($operatorInstallCommands)
        ->toContain('php apps/gateway/artisan key:generate')
        ->toContain('php apps/gateway/artisan migrate --force')
        ->toContain('php apps/gateway/artisan tinker --execute')
        ->toContain('LocalGatewaySettings::current()')
        ->toContain('/home/orbit/.config/orbit/.env')
        ->toContain('apps/gateway/vendor/autoload.php')
        ->toContain('apps/cli/vendor/autoload.php')
        ->toContain('Prepared gateway vendor dependencies are required for Docker host-launcher checkout')
        ->toContain('Prepared CLI vendor dependencies are required for Docker current checkout')
        ->toContain('rm -rf')
        ->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->toContain("cp '\\''/home/orbit/orbit/apps/cli/.env'\\'' apps/cli/.env")
        ->not->toContain('orbit-e2e-run123-operator-orbit-gateway')
        ->and($gatewayInstallCommands)
        ->toContain('php apps/gateway/artisan key:generate')
        ->toContain('php apps/gateway/artisan migrate --force')
        ->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json')
        ->toContain('/home/orbit/.config/orbit/.env')
        ->toContain('apps/gateway/vendor/autoload.php')
        ->toContain('apps/cli/vendor/autoload.php')
        ->toContain('Prepared gateway vendor dependencies are required for Docker host-launcher checkout')
        ->toContain('Prepared CLI vendor dependencies are required for Docker current checkout')
        ->toContain('rm -rf')
        ->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->toContain("cp '\\''/home/orbit/orbit/apps/cli/.env'\\'' apps/cli/.env")
        ->not->toContain('orbit-e2e-run123-gateway-orbit-gateway')
        ->and($devInstallCommands)
        ->toContain('php apps/gateway/artisan key:generate')
        ->toContain('php apps/gateway/artisan migrate --force')
        ->toContain('php apps/gateway/artisan tinker --execute')
        ->toContain('LocalGatewaySettings::current()')
        ->toContain('/home/orbit/.config/orbit/.env')
        ->toContain('apps/gateway/vendor/autoload.php')
        ->toContain('apps/cli/vendor/autoload.php')
        ->toContain('Prepared gateway vendor dependencies are required for Docker host-launcher checkout')
        ->toContain('Prepared CLI vendor dependencies are required for Docker current checkout')
        ->toContain('rm -rf')
        ->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->toContain("cp '\\''/home/orbit/orbit/apps/cli/.env'\\'' apps/cli/.env")
        ->not->toContain('orbit-e2e-run123-dev-orbit-gateway');
    expect($paths)->toBe([
        'operator' => '/home/orbit/orbit-current',
        'gateway' => '/home/orbit/orbit-current',
        'dev' => '/home/orbit/orbit-current',
    ]);
});

it('refreshes Docker gateway checkout host keys through explicit host Artisan', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $host = new DockerHost(E2EConfig::fromEnvironment());
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGateway,
        operator: new DockerInstance($host, 'orbit-e2e-run123-operator', 'orbit-e2e-run123'),
        gateway: new DockerInstance($host, 'orbit-e2e-run123-gateway', 'orbit-e2e-run123'),
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );

    E2ECurrentCheckout::installOnTopology($topology, roles: ['gateway']);

    $gatewayInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-gateway'"),
    )));
    $wrapperSymlinkCommandIndex = array_find_key(
        $commands,
        fn (string $command): bool => str_contains($command, '/home/orbit/orbit-current/apps/cli/orbit')
            && str_contains($command, '/usr/local/bin/orbit'),
    );
    $hostKeyRefreshCommandIndex = array_find_key(
        $commands,
        fn (string $command): bool => str_contains($command, 'orbit:internal:pin-node-host-keys --json'),
    );

    expect($gatewayInstallCommands)
        ->toContain('php apps/gateway/artisan key:generate')
        ->toContain('php apps/gateway/artisan migrate --force')
        ->toContain('orbit:internal:pin-node-host-keys --json')
        ->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json')
        ->not->toContain('sudo docker exec --env')
        ->not->toContain('orbit-e2e-run123-gateway-orbit-gateway')
        ->not->toContain('orbit orbit:internal:pin-node-host-keys --json')
        ->not->toContain('php artisan orbit:internal:pin-node-host-keys --json')
        ->toContain('Prepared gateway vendor dependencies are required for Docker host-launcher checkout')
        ->toContain('Prepared CLI vendor dependencies are required for Docker current checkout')
        ->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->and($wrapperSymlinkCommandIndex)->toBeInt()
        ->and($hostKeyRefreshCommandIndex)->toBeInt()
        ->and($wrapperSymlinkCommandIndex)->toBeLessThan($hostKeyRefreshCommandIndex);
});

it('shares one 600 second timeout budget across split install phases', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $timeouts = [];
    $instance = currentCheckoutFakeInstance($commands, timeouts: $timeouts);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $times = [1000.0, 1000.0, 1120.0, 1300.0, 1450.0];

    E2ECurrentCheckout::useNowResolverForTests(function () use (&$times): float {
        return array_shift($times) ?? 1450.0;
    });

    E2ECurrentCheckout::install($instance, 'orbit', $key);

    expect($timeouts)->toBe([
        600,
        480,
        300,
        150,
    ]);
});

it('can cache the checkout install and clone isolated runtime paths', function (): void {
    $previous = getenv('ORBIT_E2E_CHECKOUT_CACHE');
    putenv('ORBIT_E2E_CHECKOUT_CACHE=process');

    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    try {
        $firstPath = E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');
        $secondPath = E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

        $commandOutput = implode("\n", $commands);

        expect($firstPath)->toStartWith('/home/orbit/orbit-current-')
            ->and($secondPath)->toStartWith('/home/orbit/orbit-current-')
            ->and($secondPath)->not->toBe($firstPath)
            ->and(substr_count($commandOutput, 'tar --warning=no-unknown-keyword -xzf /tmp/orbit-current.tar.gz'))->toBe(1)
            ->and($commandOutput)->toContain('! -name .env -exec sh -c')
            ->and($commandOutput)->toContain('cp -al "$path" "$target"/')
            ->and($commandOutput)->toContain('dest="$target/$(basename "$path")"')
            ->and($commandOutput)->toContain('rm -rf "$dest"; cp -a --reflink=always')
            ->and($commandOutput)->toContain('rm -rf "$dest"; cp -a "$path" "$target"/')
            ->and($commandOutput)->toContain('cp -a --reflink=always')
            ->and($commandOutput)->toContain("cd '{$firstPath}' && if [ -f '/home/orbit/orbit-current-base-")
            ->and($commandOutput)->toContain("cd '{$secondPath}' && if [ -f '/home/orbit/orbit-current-base-")
            ->and($commandOutput)->not->toContain("/apps/gateway/vendor' '{$firstPath}/apps/gateway/vendor")
            ->and($commandOutput)->not->toContain("/apps/gateway/vendor' '{$secondPath}/apps/gateway/vendor")
            ->and($commandOutput)->not->toContain('chmod -R a-w')
            ->and($commandOutput)->toContain('! -name composer ! -name autoload.php -exec sh -c')
            ->and($commandOutput)->toContain('cp -al "$path" "$target"/')
            ->and($commandOutput)->toContain('cp -a --reflink=always "$path" "$target"/')
            ->and($commandOutput)->toContain('cp -a "$path" "$target"/')
            ->and($commandOutput)->not->toContain('-exec ln -s')
            ->and($commandOutput)->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
            ->and($commandOutput)->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
            ->and(substr_count($commandOutput, 'copy '))->toBe(1)
            ->and($commandOutput)->not->toMatch("/cp -al '\\/home\\/orbit\\/orbit-current-base-[0-9a-f]+' '\\/home\\/orbit\\/orbit-current-[^']+'/")
            ->and($commandOutput)->not->toContain("/apps/gateway/database/database.sqlite '{$firstPath}'/apps/gateway/database/database.sqlite")
            ->and($commandOutput)->not->toContain("rm -f '{$firstPath}'/apps/gateway/database/database.sqlite")
            ->and($commandOutput)->not->toContain("/apps/gateway/database/database.sqlite '{$secondPath}'/apps/gateway/database/database.sqlite")
            ->and($commandOutput)->not->toContain("rm -f '{$secondPath}'/apps/gateway/database/database.sqlite")
            ->and($commandOutput)->not->toContain("/storage' '{$firstPath}/storage")
            ->and($commandOutput)->not->toContain("/storage' '{$secondPath}/storage")
            ->and($commandOutput)->not->toContain("/apps/gateway/storage/app' '{$firstPath}/apps/gateway/storage/app")
            ->and($commandOutput)->not->toContain("/apps/gateway/storage/app' '{$secondPath}/apps/gateway/storage/app");
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_CHECKOUT_CACHE');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_CACHE={$previous}");
        }
    }
});

it('reuses the shared checkout archive after flushing in-process checkout state', function (): void {
    $previousCache = getenv('ORBIT_E2E_CHECKOUT_CACHE');
    $previousCacheDir = getenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
    $cacheDir = sys_get_temp_dir().'/orbit-checkout-archive-test-'.bin2hex(random_bytes(4));
    $tarBuilds = 0;

    putenv('ORBIT_E2E_CHECKOUT_CACHE=process');
    putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$cacheDir}");

    Process::fake(function ($process) use (&$tarBuilds) {
        if (str_starts_with((string) $process->command, 'COPYFILE_DISABLE=1 tar ')) {
            $tarBuilds++;

            if (preg_match("/ -czf '([^']+)' /", (string) $process->command, $matches) === 1) {
                file_put_contents($matches[1], 'archive');
            }
        }

        return Process::result();
    });

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    try {
        E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');
        E2ECurrentCheckout::flushCache();
        E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

        expect($tarBuilds)->toBe(1)
            ->and(substr_count(implode("\n", $commands), 'copy '))->toBe(2);
    } finally {
        E2ECurrentCheckout::flushCache();
        Process::run('rm -rf '.escapeshellarg($cacheDir));

        if ($previousCache === false) {
            putenv('ORBIT_E2E_CHECKOUT_CACHE');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_CACHE={$previousCache}");
        }

        if ($previousCacheDir === false) {
            putenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$previousCacheDir}");
        }
    }
});

it('only records clone timing when reusing a cached base checkout', function (): void {
    $previousCache = getenv('ORBIT_E2E_CHECKOUT_CACHE');
    $previousCacheDir = getenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
    $cacheDir = sys_get_temp_dir().'/orbit-e2e-checkout-timing-'.bin2hex(random_bytes(4));

    putenv('ORBIT_E2E_CHECKOUT_CACHE=process');
    putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$cacheDir}");
    E2ECurrentCheckout::flushCache();

    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $timer = new E2EPhaseTimer;

    try {
        E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit', timer: $timer);
        E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit', timer: $timer);

        expect(array_column($timer->events(), 'name'))->toBe([
            'checkout.archive',
            'checkout.copy',
            'checkout.extract',
            'checkout.vendor',
            'checkout.runtime-state',
            'checkout.migrate',
            'checkout.cache-clone',
        ]);
    } finally {
        E2ECurrentCheckout::flushCache();
        File::deleteDirectory($cacheDir);

        if ($previousCache === false) {
            putenv('ORBIT_E2E_CHECKOUT_CACHE');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_CACHE={$previousCache}");
        }

        if ($previousCacheDir === false) {
            putenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$previousCacheDir}");
        }
    }
});

it('can install the current checkout on selected topology roles', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $operatorCommands = [];
    $gatewayCommands = [];
    $devCommands = [];
    $prodCommands = [];
    $agentCommands = [];

    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdevAppprod,
        operator: currentCheckoutFakeInstance($operatorCommands, 'operator'),
        gateway: currentCheckoutFakeInstance($gatewayCommands, 'gateway'),
        dev: currentCheckoutFakeInstance($devCommands, 'dev'),
        prod: currentCheckoutFakeInstance($prodCommands, 'prod'),
        agent: currentCheckoutFakeInstance($agentCommands, 'agent'),
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );

    $paths = E2ECurrentCheckout::installOnTopology($topology, roles: ['operator', 'gateway', 'dev', 'prod', 'agent']);

    expect($paths)->toBe([
        'operator' => '/home/orbit/orbit-current',
        'gateway' => '/home/orbit/orbit-current',
        'dev' => '/home/orbit/orbit-current',
        'prod' => '/home/orbit/orbit-current',
        'agent' => '/home/orbit/orbit-current',
    ]);

    expect(implode("\n", $operatorCommands))->toContain('/home/orbit/.config/orbit/.env');
    expect(implode("\n", $operatorCommands))->toContain('LocalGatewaySettings::current()');
    expect(implode("\n", $operatorCommands))->toContain('https://10.6.0.2');
    expect(implode("\n", $gatewayCommands))->toContain('/home/orbit/.config/orbit/.env');
    expect(implode("\n", $gatewayCommands))->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
    expect(implode("\n", $gatewayCommands))->not->toContain('checkout.gateway-settings');
    expect(implode("\n", $devCommands))->toContain('/home/orbit/.config/orbit/.env');
    expect(implode("\n", $devCommands))->toContain('LocalGatewaySettings::current()');
    expect(implode("\n", $devCommands))->toContain('https://10.6.0.2');
    expect(implode("\n", $devCommands))->not->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
    expect(implode("\n", $prodCommands))->toContain('/home/orbit/.config/orbit/.env');
    expect(implode("\n", $prodCommands))->toContain('LocalGatewaySettings::current()');
    expect(implode("\n", $prodCommands))->toContain('https://10.6.0.2');
    expect(implode("\n", $prodCommands))->not->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
    expect(implode("\n", $agentCommands))->toContain('/home/orbit/.config/orbit/.env');
    expect(implode("\n", $agentCommands))->toContain('LocalGatewaySettings::current()');
    expect(implode("\n", $agentCommands))->toContain('https://10.6.0.2');
    expect(implode("\n", $agentCommands))->not->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
});

it('passes checkout timing through topology installation', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $operatorCommands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: currentCheckoutFakeInstance($operatorCommands, 'operator'),
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );
    $timer = new E2EPhaseTimer;

    E2ECurrentCheckout::installOnTopology($topology, roles: ['operator'], timer: $timer);

    expect(array_column($timer->events(), 'name'))->toContain('operator checkout.archive', 'operator checkout.migrate');
});

it('installs source-mounted topology roles concurrently when fork workers are available', function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
        $this->markTestSkipped('pcntl is required for checkout role worker concurrency.');
    }

    $logPath = tempnam(sys_get_temp_dir(), 'orbit-checkout-role-workers-');
    $operatorCommands = [];
    $gatewayCommands = [];
    $devCommands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdev,
        operator: currentCheckoutFakeSourceMountedInstance($operatorCommands, 'operator'),
        gateway: currentCheckoutFakeSourceMountedInstance($gatewayCommands, 'gateway'),
        dev: currentCheckoutFakeSourceMountedInstance($devCommands, 'dev'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );
    $timer = new E2EPhaseTimer;

    E2ECurrentCheckout::useInstallerForTests(function (string $role, E2EPhaseTimer $roleTimer) use ($logPath): string {
        file_put_contents($logPath, "{$role}:start:".microtime(true).PHP_EOL, FILE_APPEND | LOCK_EX);
        usleep(250_000);
        file_put_contents($logPath, "{$role}:done:".microtime(true).PHP_EOL, FILE_APPEND | LOCK_EX);
        $roleTimer->recordExternal('checkout.test-worker', 0.25);

        return "/home/orbit/orbit-run-{$role}";
    });

    try {
        $start = microtime(true);

        $paths = E2ECurrentCheckout::installOnTopology($topology, roles: ['operator', 'gateway', 'dev'], timer: $timer);

        $elapsed = microtime(true) - $start;
        $lines = file($logPath, FILE_IGNORE_NEW_LINES);
    } finally {
        @unlink($logPath);
    }

    $starts = array_values(array_filter($lines, fn (string $line): bool => str_contains($line, ':start:')));

    expect($paths)->toBe([
        'operator' => '/home/orbit/orbit-run-operator',
        'gateway' => '/home/orbit/orbit-run-gateway',
        'dev' => '/home/orbit/orbit-run-dev',
    ])
        ->and($starts)->toHaveCount(3)
        ->and($elapsed)->toBeLessThan(0.65)
        ->and(array_column($timer->events(), 'name'))->toContain(
            'operator checkout.test-worker',
            'gateway checkout.test-worker',
            'dev checkout.test-worker',
        );
});

it('streams checkout timings from the topology harness timer child', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $operatorCommands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: currentCheckoutFakeInstance($operatorCommands, 'operator'),
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );
    $lines = [];
    $timer = new E2EPhaseTimer(
        stream: true,
        writer: function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    );

    (new E2ETopologyHarness($topology))
        ->setTimer($timer)
        ->withCurrentCheckout(roles: ['operator']);

    expect($lines[0])->toBe('[orbit-e2e] checkout operator checkout.archive started')
        ->and(implode("\n", $lines))->toContain('checkout operator checkout.migrate done ');
});
