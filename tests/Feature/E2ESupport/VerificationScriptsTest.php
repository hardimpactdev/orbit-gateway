<?php

declare(strict_types=1);

use App\E2E\Support\E2ECurrentCheckout;
use Symfony\Component\Process\Process;

it('keeps ephemeral e2e on the Incus backend separate from default pest tests', function (): void {
    expect(repo_path('bin/e2e'))->not->toBeFile();
});

it('does not expose a standing live smoke test lane', function (): void {
    $composer = json_decode(file_get_contents(repo_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect(repo_path('bin/live-smoke'))->not->toBeFile()
        ->and($composer['scripts'])->not->toHaveKey('test:live');
});

it('keeps composer test:live and bin/live-smoke out of every doc surface agents read', function (): void {
    $files = collect([
        repo_path('AGENTS.md'),
        repo_path('README.md'),
    ]);

    foreach (['.agents/skills', 'docs/superpowers/plans'] as $relative) {
        $absolute = repo_path($relative);

        if (! is_dir($absolute)) {
            continue;
        }

        $files = $files->merge(
            collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)))
                ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'md')
                ->map(fn (SplFileInfo $file): string => $file->getPathname())
        );
    }

    $offenders = $files
        ->filter(fn (string $path): bool => is_file($path))
        ->mapWithKeys(fn (string $path): array => [$path => (string) file_get_contents($path)])
        ->filter(fn (string $contents): bool => str_contains($contents, 'composer test:live') || str_contains($contents, 'bin/live-smoke'))
        ->keys()
        ->map(fn (string $path): string => str_replace(repo_path().'/', '', $path))
        ->sort()
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('reports command docs lint severities in agent format', function (): void {
    $composer = json_decode(file_get_contents(repo_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);
    $docsLintScript = implode("\n", (array) $composer['scripts']['docs-lint']);

    expect($docsLintScript)
        ->toContain('artisan librarian:lint')
        ->toContain('--format=agent')
        ->toContain('--path=domains')
        ->toContain('--path=testing')
        ->toContain('--group=references')
        ->not->toContain('--path=content/')
        ->not->toContain('--strict');
});

it('keeps the aggregate quality gate complete', function (): void {
    $composer = json_decode(file_get_contents(repo_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    // The gate fans out docs-lint, phpstan, rector, and pint concurrently while
    // the default Pest suite runs in parallel through `bin/quality-check.sh`.
    expect($composer['scripts']['quality-check'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'bin/quality-check.sh',
    ]);

    $script = (string) file_get_contents(repo_path('bin/quality-check.sh'));

    expect($script)
        ->toContain('librarian:lint')
        ->toContain('--path=testing')
        ->toContain('--group=references')
        ->toContain('phpstan analyse')
        ->toContain('rector process')
        ->toContain('bin/orbit-gateway-vendor-bin pint')
        ->toContain('cd apps/cli && vendor/bin/phpstan analyse')
        ->toContain('cd apps/docs && vendor/bin/phpstan analyse')
        ->toContain('cd packages/core && vendor/bin/phpstan analyse')
        ->toContain('cd apps/e2e && vendor/bin/phpstan analyse')
        ->toContain('cd apps/cli && vendor/bin/rector process')
        ->toContain('cd apps/docs && vendor/bin/rector process')
        ->toContain('cd packages/core && vendor/bin/rector process')
        ->toContain('cd apps/e2e && vendor/bin/rector process')
        ->toContain('cd apps/cli && vendor/bin/pint')
        ->toContain('cd apps/docs && vendor/bin/pint')
        ->toContain('cd packages/core && vendor/bin/pint')
        ->toContain('cd apps/e2e && vendor/bin/pint')
        ->toContain('bin/orbit-cli-pest')
        ->toContain('bin/orbit-docs-pest')
        ->toContain('cd packages/core && vendor/bin/pest')
        ->toContain('cd apps/e2e && vendor/bin/pest')
        ->toContain('--exclude-group=e2e-binary')
        ->toContain('--exclude-group=e2e-provision')
        ->toContain('bin/orbit-gateway-pest')
        ->toContain('--exclude-group=e2e')
        ->toContain('--exclude-group=slow')
        ->toContain('--parallel')
        ->toContain('--compact');
});

it('keeps default composer tests out of e2e lanes', function (): void {
    $composer = json_decode(file_get_contents(repo_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);
    $e2eComposer = json_decode(file_get_contents(repo_path('apps/e2e/composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);
    $appLocalE2eInMemoryScript = 'vendor/bin/pest --exclude-group=e2e-binary --exclude-group=e2e-binary-acceptance --exclude-group=e2e-feature --exclude-group=e2e-provision --exclude-group=e2e-topology-contract --compact';
    $defaultTestScripts = implode("\n", $composer['scripts']['test']);
    $slowTestScripts = implode("\n", $composer['scripts']['test:slow']);

    expect($composer['scripts']['test'])
        ->sequence(
            fn ($script) => $script->toBe('Composer\\Config::disableProcessTimeout'),
            fn ($script) => $script->toContain('artisan config:clear'),
            fn ($script) => $script
                ->toContain('pest --exclude-group=e2e')
                ->toContain('--parallel')
                ->toContain('--compact'),
            fn ($script) => $script->toContain('bin/orbit-cli-pest --compact'),
            fn ($script) => $script->toContain('bin/orbit-docs-pest --compact'),
            fn ($script) => $script->toContain('cd packages/core && vendor/bin/pest --compact'),
        )->and($composer['scripts']['test:slow'])
        ->sequence(
            fn ($script) => $script->toBe('Composer\\Config::disableProcessTimeout'),
            fn ($script) => $script->toContain('artisan config:clear'),
            fn ($script) => $script
                ->toContain('pest --exclude-group=e2e')
                ->toContain('--parallel')
                ->toContain('--compact'),
            fn ($script) => $script->toContain('bin/orbit-cli-pest --compact'),
            fn ($script) => $script->toContain('bin/orbit-docs-pest --compact'),
            fn ($script) => $script->toContain('cd packages/core && vendor/bin/pest --compact'),
        )->and($e2eComposer['scripts']['test'])
        ->sequence(
            fn ($script) => $script->toBe('@php artisan config:clear --ansi'),
            fn ($script) => $script->toBe($appLocalE2eInMemoryScript),
        );

    expect($defaultTestScripts)
        ->not->toContain('apps/e2e')
        ->not->toContain('bin/orbit-e2e')
        ->not->toContain('ORBIT_E2E=1')
        ->not->toContain('--group=e2e')
        ->and($slowTestScripts)
        ->not->toContain('apps/e2e')
        ->not->toContain('bin/orbit-e2e')
        ->not->toContain('ORBIT_E2E=1')
        ->not->toContain('--group=e2e');

    $e2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; bin/orbit-e2e-artisan e2e:test @additional_args';
    $dockerE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=docker bin/orbit-e2e-artisan e2e:test @additional_args';
    $dockerCanaryE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=docker bin/orbit-e2e-artisan e2e:test --canary @additional_args';
    $incusE2eScript = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E_LANES=incus bin/orbit-e2e-artisan e2e:test @additional_args';

    expect($composer['scripts']['test:e2e'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        $e2eScript,
    ])->and($composer['scripts']['test:e2e:docker'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        $dockerE2eScript,
    ])->and($composer['scripts']['test:e2e:docker:canary'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        $dockerCanaryE2eScript,
    ])->and($composer['scripts']['test:e2e:incus'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        $incusE2eScript,
    ]);

    expect($composer['scripts']['test:e2e:provision'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'composer test:e2e:provision:docker',
        'composer test:e2e:provision:incus',
    ])->and($composer['scripts']['test:e2e:provision:docker'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; bin/orbit-e2e-artisan e2e:prepare-docker-hosts --force --rebuild operator_gateway_app-dev_app-prod_agent_websocket @additional_args',
    ])->and($composer['scripts']['test:e2e:provision:incus'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; cd apps/e2e && ORBIT_E2E=1 vendor/bin/pest --group=e2e-provision --fail-on-empty-test-suite @additional_args',
    ])->and($composer['scripts']['test:e2e:next'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'cd apps/e2e && vendor/bin/pest --compact @additional_args',
    ])->and($composer['scripts'])->not->toHaveKey('test:e2e:provisioning')
        ->and($composer['scripts'])->not->toHaveKey('test:e2e:features')
        ->and($composer['scripts'])->not->toHaveKey('test:e2e:features:docker');
});

it('documents the supported verification lanes', function (): void {
    $testing = implode("\n", [
        file_get_contents(repo_path('apps/docs/content/testing/README.md')),
        file_get_contents(repo_path('apps/docs/content/testing/in-memory/README.md')),
        file_get_contents(repo_path('apps/docs/content/testing/e2e/README.md')),
    ]);

    expect(repo_path('TESTING.md'))->not->toBeFile();

    expect($testing)
        ->toContain('# In-memory tests')
        ->toContain('# E2E testing')
        ->toContain('Feature E2E backed by Docker')
        ->toContain('composer test:e2e')
        ->toContain('composer test:e2e:docker')
        ->toContain('composer test:e2e:incus')
        ->toContain('composer test:e2e:provision:docker')
        ->toContain('composer test:e2e:provision:incus')
        ->toContain('composer test:e2e:provision')
        ->toContain('composer test')
        ->not->toContain('composer test:e2e:features')
        ->not->toContain('composer test:live')
        ->not->toContain('bin/live-smoke')
        ->not->toContain('Standing Live Node Rule');
});

it('documents the e2e docker benchmark protocol', function (): void {
    $testing = file_get_contents(repo_path('apps/docs/content/testing/e2e/performance.md'));

    expect($testing)
        ->toContain('## E2E Docker lane - benchmark protocol')
        ->toContain('ORBIT_E2E_TIMINGS=1 \\')
        ->toContain('composer test:e2e:docker:canary \\')
        ->toContain('2>&1 | tee /tmp/e2e-canary.log | awk -f bin/e2e-timings.awk')
        ->toContain('composer test:e2e:docker \\')
        ->toContain('2>&1 | tee /tmp/e2e-full.log | awk -f bin/e2e-timings.awk')
        ->toContain('## Required SSH multiplexing for measured Docker baselines')
        ->toContain('ControlMaster auto')
        ->toContain('ControlPath ~/.ssh/cm-%r@%h:%p.sock')
        ->toContain('ssh -G sidecar1')
        ->toContain('time ssh -o BatchMode=yes sidecar1 true')
        ->toContain('10-20 ms');
});

it('keeps active testing docs on current e2e script names', function (): void {
    $testing = file_get_contents(repo_path('apps/docs/content/testing/e2e/README.md'));

    expect($testing)
        ->toContain('composer test:e2e')
        ->toContain('composer test:e2e:docker')
        ->toContain('composer test:e2e:incus')
        ->toContain('composer test:e2e:provision')
        ->not->toContain('composer test:e2e:features')
        ->not->toContain('composer test:e2e:features:docker');
});

it('keeps reusable e2e support code free of Pest-only expectations', function (): void {
    $supportFiles = collect(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(repo_path('apps/gateway/app/E2E/Support')),
    ))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
        ->mapWithKeys(fn (SplFileInfo $file): array => [
            $file->getPathname() => file_get_contents($file->getPathname()) ?: '',
        ]);

    expect($supportFiles)->each(fn ($contents) => $contents->not->toContain('expect('));
});

it('does not expose hetzner e2e support', function (): void {
    $composer = json_decode(file_get_contents(repo_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts'])
        ->not->toHaveKey('test:e2e:hcloud-docker')
        ->not->toHaveKey('e2e:reap-hcloud')
        ->not->toHaveKey('e2e:prepare-hcloud-images');

    expect(is_file(repo_path('apps/gateway/app/Console/Commands/E2EReapHcloudCommand.php')))->toBeFalse()
        ->and(is_file(repo_path('apps/gateway/app/Console/Commands/E2ETestHcloudDockerCommand.php')))->toBeFalse()
        ->and(is_file(repo_path('apps/gateway/app/E2E/Support/HcloudProvider.php')))->toBeFalse()
        ->and(is_file(repo_path('apps/gateway/app/E2E/Support/HcloudInstance.php')))->toBeFalse()
        ->and(is_file(repo_path('apps/gateway/app/Services/E2E/HcloudE2EReaper.php')))->toBeFalse()
        ->and(is_file(repo_path('apps/gateway/app/Services/E2E/HcloudDockerE2ERunner.php')))->toBeFalse()
        ->and(is_file(repo_path('apps/gateway/app/Services/E2E/HcloudDockerE2ERunOptions.php')))->toBeFalse();
});

it('exposes e2e preflight, preparation, and cleanup helpers', function (): void {
    $composer = json_decode(file_get_contents(repo_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    $e2eEnvPrefix = 'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a;';

    expect($composer['scripts']['e2e:preflight'])->toBe("{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:preflight @additional_args")
        ->and($composer['scripts']['e2e:prepare-docker-runtime'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:prepare-docker-runtime @additional_args",
        ])->and($composer['scripts']['e2e:prepare-docker-topology'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:prepare-docker-topology @additional_args",
        ])->and($composer['scripts']['e2e:prepare-docker-hosts'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:prepare-docker-hosts @additional_args",
        ])->and($composer['scripts']['e2e:ensure-artifacts'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:ensure-artifacts @additional_args",
        ])->and($composer['scripts']['e2e:prepare-base-image'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:prepare-base-image @additional_args",
        ])->and($composer['scripts']['e2e:prepare-topology'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:prepare-topology @additional_args",
        ])->and($composer['scripts']['e2e:prepare-warm-topology'])->toBe([
            'Composer\\Config::disableProcessTimeout',
            "{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:prepare-warm-topology @additional_args",
        ])->and($composer['scripts']['e2e:reap-incus'])->toBe("{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:reap-incus @additional_args")
        ->and($composer['scripts']['e2e:reap-docker'])->toBe("{$e2eEnvPrefix} bin/orbit-e2e-artisan e2e:reap-docker @additional_args");
});

it('keeps reusable e2e harness code out of the Tests namespace for app commands', function (): void {
    $appFiles = collect(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(repo_path('apps/gateway/app'), FilesystemIterator::SKIP_DOTS),
    ))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php');

    $offenders = $appFiles
        ->filter(fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), 'Tests\\E2E\\Support'))
        ->map(fn (SplFileInfo $file): string => str_replace(repo_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe([]);

    expect(is_file(repo_path('apps/gateway/app/Console/Commands/E2EPrepareHcloudImagesCommand.php')))->toBeFalse();
});

it('registers ephemeral e2e as a guarded Pest group', function (): void {
    $phpunit = file_get_contents(repo_path('apps/gateway/phpunit.xml'));
    $pest = file_get_contents(repo_path('apps/gateway/tests/Pest.php'));

    expect($phpunit)
        ->toContain('<testsuite name="E2E">')
        ->toContain('<directory>tests/E2E</directory>')
        ->and($pest)
        ->toContain('ORBIT_E2E')
        ->toContain("->group('e2e')")
        ->toContain("->in('E2E')");
});

it('keeps persisted orbit certificate material out of the docker build context', function (): void {
    $dockerignore = file_get_contents(repo_path('docker/e2e/topology/Dockerfile.dockerignore'));

    expect($dockerignore)
        ->toContain('apps/gateway/storage/app/orbit/ca/**')
        ->toContain('apps/gateway/storage/app/orbit/certs/**')
        ->toContain('apps/gateway/storage/app/orbit/keys/**');
});

it('keeps local composer dependencies in the docker topology build context', function (): void {
    $dockerignore = file_get_contents(repo_path('docker/e2e/topology/Dockerfile.dockerignore'));
    $ignoredPaths = preg_split('/\R/', trim($dockerignore));

    expect(in_array('vendor', $ignoredPaths, true))->toBeFalse();
});

it('keeps persisted orbit certificate material out of source-less docker topology preparation', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->toContain('LABEL org.orbit.e2e.source="prepared-checkout"')
        ->not->toContain('/opt/orbit-source')
        ->not->toContain('cp -a /opt/orbit-source/. /home/operator/orbit/')
        ->not->toContain('cp -a /opt/orbit-source/. /home/orbit/orbit/');

    expect(E2ECurrentCheckout::archiveExcludePatterns())
        ->toContain('./apps/gateway/storage/app/orbit/ca/*')
        ->toContain('./apps/gateway/storage/app/orbit/certs/*')
        ->toContain('./apps/gateway/storage/app/orbit/keys/*');
});

it('keeps the docker topology host image free of host Composer dependencies', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/e2e/topology/Dockerfile'));

    expect($dockerfile)
        ->not->toContain('COPY --from=composer')
        ->not->toContain('composer install');
});

it('installs Docker via docker.com and downloads the prebuilt CLI binary instead of host PHP', function (): void {
    $script = file_get_contents(repo_path('bin/install-orbit'));

    expect($script)
        ->toContain('download.docker.com')
        ->toContain('docker.gpg')
        ->toContain('docker-ce')
        ->toContain('download_cli_binary')
        ->not->toContain('ppa:ondrej/php')
        ->not->toContain('php8.5-cli')
        ->not->toContain('packages.sury.org/php')
        ->not->toContain('sury-php.gpg')
        ->not->toContain('ppa.launchpadcontent.net')
        ->not->toContain('keyserver.ubuntu.com');
});

it('does not wait for guest initialization tooling before mutating apt on Ubuntu', function (): void {
    $script = file_get_contents(repo_path('bin/install-orbit'));

    expect($script)->not->toContain('cloud-init');
});

it('aggregates e2e timing lines by label and event', function (): void {
    $input = <<<'TEXT'
[orbit-e2e] topology acquire 1.25s
[orbit-e2e] topology acquire 2.50s
[orbit-e2e] topology acquire 3.75s
[orbit-e2e] topology reset 4.00s
[orbit-e2e] malformed
[orbit-e2e] topology acquire nope
noise line
[orbit-e2e] node new 9.00s
[orbit-e2e] checkout checkout operator checkout.copy 0.75s
TEXT;

    $process = new Process([
        'awk',
        '-f',
        repo_path('bin/e2e-timings.awk'),
    ]);
    $process->setInput($input);
    $process->mustRun();

    $lines = collect(preg_split('/\R+/', trim($process->getOutput())) ?: [])
        ->filter()
        ->sort()
        ->values()
        ->all();

    expect($lines)->toBe([
        'checkout/checkout.operator.checkout.copy n=1 p50=0.75 p95=0.75',
        'node/new n=1 p50=9 p95=9',
        'topology/acquire n=3 p50=2.5 p95=3.75',
        'topology/reset n=1 p50=4 p95=4',
    ]);
});

it('does not install host Supervisor because runtime processes live inside Docker containers', function (): void {
    $script = file_get_contents(repo_path('bin/install-orbit'));

    expect($script)
        ->not->toContain('supervisor')
        ->toContain('orbit-gateway:current');
});

it('installs the SSH client as a operator-node provisioning prerequisite', function (): void {
    $script = file_get_contents(repo_path('bin/install-orbit'));

    expect($script)->toContain('openssh-client');
});

it('does not install host PHP SQLite packages because the CLI binary embeds pdo_sqlite', function (): void {
    $script = file_get_contents(repo_path('bin/install-orbit'));

    expect(preg_match_all('/^\s+sqlite3\s+\\\\$/m', $script))->toBe(0)
        ->and($script)->not->toContain('php8.4-sqlite3')
        ->and($script)->not->toContain('php8.5-sqlite3')
        ->and($script)->toContain('orbit-gateway:current');
});

it('aligns orbit checkout and config root ownership so non-root users can write after container bootstrap', function (): void {
    $script = file_get_contents(repo_path('bin/install-orbit'));

    expect($script)
        ->toContain('finalize_target_ownership')
        ->toContain('finalize_config_root_ownership')
        ->toContain('--no-same-owner')
        ->toContain('sudo_run chown -R "$owner:$group" "$CONFIG_ROOT"')
        ->toContain('chown -R');
});

it('documents e2e topology timing event names', function (): void {
    $testing = file_get_contents(repo_path('apps/docs/content/testing/e2e/performance.md'));

    expect($testing)
        ->toContain('batch.copy-start')
        ->toContain('agent-ready.<role>')
        ->toContain('command-ready.<role>')
        ->toContain('wireguard')
        ->toContain('cleanup.<role>');
});

it('does not expose stale per-topology feature e2e aliases', function (): void {
    $composer = json_decode(file_get_contents(repo_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts'])
        ->not->toHaveKey('test:e2e:features:operator')
        ->not->toHaveKey('test:e2e:features:operator-gateway')
        ->not->toHaveKey('test:e2e:features:operator-gateway-dev')
        ->not->toHaveKey('test:e2e:features:operator-gateway-dev-prod')
        ->not->toHaveKey('test:e2e:features:parallel')
        ->not->toHaveKey('test:e2e:features:docker:operator-gateway-dev-prod');
});

it('runs the topology contract against the Docker full topology by default', function (): void {
    $composer = json_decode(file_get_contents(repo_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['test:e2e:topology-contract'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; cd apps/e2e && ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=docker vendor/bin/pest --group=e2e-topology-contract-operator_gateway_app-dev_app-prod_agent_websocket @additional_args',
    ])->and($composer['scripts'])
        ->not->toHaveKey('test:e2e:topology-contract:operator')
        ->not->toHaveKey('test:e2e:topology-contract:operator-gateway')
        ->not->toHaveKey('test:e2e:topology-contract:operator-gateway-dev')
        ->not->toHaveKey('test:e2e:topology-contract:operator-gateway-dev-prod');
});
