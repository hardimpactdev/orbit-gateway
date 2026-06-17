<?php

declare(strict_types=1);
use Symfony\Component\Process\Process;

it('publishes cli artifacts gateway image and release manifest on GitHub releases', function (): void {
    $workflow = file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($workflow)
        ->toContain('name: Orbit Release')
        ->toContain('types: [published]')
        ->toContain('packages: write')
        ->toContain('contents: write')
        ->toContain('bin/orbit-version')
        ->toContain('Release tag')
        ->toContain('does not match VERSION')
        ->toContain('bin/orbit-prepare-release-package --package="$package"')
        ->toContain('publish_split core hardimpactdev/orbit-core')
        ->toContain('publish_split cli hardimpactdev/orbit-cli')
        ->toContain('publish_split gateway hardimpactdev/orbit-gateway')
        ->toContain('hardimpactdev/orbit-core')
        ->toContain('hardimpactdev/orbit-cli')
        ->toContain('hardimpactdev/orbit-gateway')
        ->toContain('ORBIT_RELEASE_TOKEN')
        ->toContain('PACKAGIST_USERNAME')
        ->toContain('PACKAGIST_TOKEN')
        ->toContain('ghcr.io')
        ->toContain('hardimpactdev/orbit-gateway')
        ->toContain('docker buildx build')
        ->toContain('--push')
        ->toContain('--metadata-file')
        ->toContain('containerimage.digest')
        ->toContain('bin/orbit-build-cli-binary mac arm')
        ->toContain('bin/orbit-build-cli-binary linux x64')
        ->toContain('bin/orbit-release-manifest')
        ->toContain('orbit-release-manifest.json')
        ->toContain('gh release upload')
        ->toContain('orbit-linux-x64')
        ->toContain('orbit-macos-arm64')
        ->toContain('cp apps/cli/builds/dist/linux/linux-x64 orbit-linux-x64')
        ->toContain('cp apps/cli/builds/dist/mac/mac-arm orbit-macos-arm64')
        ->toContain('--cli-artifact="linux-amd64=orbit-linux-x64=orbit-linux-x64"')
        ->toContain('--cli-artifact="darwin-arm64=orbit-macos-arm64=orbit-macos-arm64"')
        ->not->toContain("sed -n \"s/.*'version' =>")
        ->not->toContain('#orbit-linux-x64')
        ->not->toContain('#orbit-macos-arm64')
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64')
        ->not->toContain('orbit'.'-runtime');
});

it('builds cli binary workflows through the shared no-dev compressed phar helper', function (): void {
    $binaryWorkflow = file_get_contents(repo_path('.github/workflows/orbit-cli-binary.yml'));
    $releaseWorkflow = file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($binaryWorkflow)
        ->toContain('zlib')
        ->toContain('bin/orbit-version')
        ->toContain('bin/orbit-build-cli-binary mac arm')
        ->toContain('bin/orbit-build-cli-binary linux x64')
        ->not->toContain("sed -n \"s/.*'version' =>")
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64');

    expect($releaseWorkflow)
        ->toContain('zlib')
        ->toContain('bin/orbit-version')
        ->toContain('bin/orbit-build-cli-binary mac arm')
        ->toContain('bin/orbit-build-cli-binary linux x64')
        ->not->toContain("sed -n \"s/.*'version' =>")
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64');
});

it('uses the root VERSION file as the single release version source', function (): void {
    $version = trim((string) file_get_contents(repo_path('VERSION')));
    $cliConfig = file_get_contents(repo_path('apps/cli/config/app.php'));
    $gatewayConfig = file_get_contents(repo_path('apps/gateway/config/app.php'));
    $gatewayWorkflow = file_get_contents(repo_path('.github/workflows/orbit-gateway-image.yml'));
    $binaryWorkflow = file_get_contents(repo_path('.github/workflows/orbit-cli-binary.yml'));

    expect($version)->toMatch('/^\d+\.\d+\.\d+$/')
        ->and(config('app.version'))->toBe($version)
        ->and(repo_path('bin/orbit-version'))->toBeFile()
        ->and($cliConfig)->toContain('VERSION')
        ->and($gatewayConfig)->toContain('VERSION')
        ->and($cliConfig)->not->toContain("'version' => '")
        ->and($gatewayConfig)->not->toContain("'version' => '")
        ->and($gatewayWorkflow)->toContain('bin/orbit-version')
        ->and($binaryWorkflow)->toContain('bin/orbit-version');
});

it('builds local e2e cli binary artifacts through the shared helper', function (): void {
    $composer = json_decode((string) file_get_contents(repo_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    $binaryScript = implode("\n", $composer['scripts']['test:e2e:binary']);
    $linuxBinaryScript = implode("\n", $composer['scripts']['test:e2e:binary:linux']);
    $dockerAcceptanceScript = implode("\n", $composer['scripts']['test:e2e:docker:binary-acceptance']);
    $combinedScripts = implode("\n", [$binaryScript, $linuxBinaryScript, $dockerAcceptanceScript]);

    expect($binaryScript)
        ->toContain('bin/orbit-build-cli-binary mac arm "$(bin/orbit-version)"')
        ->toContain('ORBIT_E2E_BINARY_PATH=$(pwd)/apps/cli/builds/dist/mac/mac-arm');

    expect($linuxBinaryScript)
        ->toContain('bin/orbit-build-cli-binary linux x64 "$(bin/orbit-version)"');

    expect($dockerAcceptanceScript)
        ->toContain('bin/orbit-build-cli-binary linux x64 "$(bin/orbit-version)"')
        ->toContain('ORBIT_E2E_BINARY_PATH_LINUX=$(pwd)/apps/cli/builds/dist/linux/linux-x64');

    expect($combinedScripts)
        ->not->toContain('rm -rf apps/cli/vendor/hardimpactdev/orbit-core')
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64');
});

it('documents the compressed phar runtime extension contract', function (): void {
    $documents = [
        file_get_contents(repo_path('apps/docs/content/tech-stack.md')),
        file_get_contents(repo_path('apps/docs/content/domains/1_node/README.md')),
        file_get_contents(repo_path('apps/docs/content/domains/1_node/node-concepts.md')),
    ];

    foreach ($documents as $document) {
        expect($document)
            ->toContain('pdo_sqlite')
            ->toContain('phar')
            ->toContain('zlib');
    }
});

it('keeps gateway image build hygiene covered by dockerignore in release workflow context', function (): void {
    $workflow = file_get_contents(repo_path('.github/workflows/orbit-release.yml'));
    $dockerignore = file_get_contents(repo_path('docker/orbit-gateway/Dockerfile.dockerignore'));

    expect($workflow)
        ->toContain('docker/orbit-gateway/Dockerfile')
        ->toContain('docker/orbit-gateway/Dockerfile.dockerignore');

    expect($dockerignore)
        ->toContain('**/.env')
        ->toContain('**/vendor')
        ->toContain('storage/logs')
        ->toContain('storage/*.sqlite')
        ->toContain('node_modules')
        ->toContain('.git');
});

it('prepares release split package manifests with the exact monorepo version', function (): void {
    $root = sys_get_temp_dir().'/orbit-release-package-'.bin2hex(random_bytes(6));

    try {
        foreach (['core', 'cli', 'gateway'] as $package) {
            $process = new Process([
                PHP_BINARY,
                repo_path('bin/orbit-prepare-release-package'),
                "--package={$package}",
                '--version=1.2.3',
                "--output={$root}/{$package}",
            ], repo_path());
            $process->run();

            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        }

        $core = json_decode((string) file_get_contents("{$root}/core/composer.json"), true, flags: JSON_THROW_ON_ERROR);
        $cli = json_decode((string) file_get_contents("{$root}/cli/composer.json"), true, flags: JSON_THROW_ON_ERROR);
        $gateway = json_decode((string) file_get_contents("{$root}/gateway/composer.json"), true, flags: JSON_THROW_ON_ERROR);

        expect($core['name'])->toBe('hardimpactdev/orbit-core')
            ->and($core['version'])->toBe('1.2.3')
            ->and("{$root}/core/composer.lock")->not->toBeFile()
            ->and($cli['name'])->toBe('hardimpactdev/orbit-cli')
            ->and($cli['version'])->toBe('1.2.3')
            ->and($cli['require']['hardimpactdev/orbit-core'])->toBe('1.2.3')
            ->and($cli)->not->toHaveKey('repositories')
            ->and("{$root}/cli/composer.lock")->not->toBeFile()
            ->and($gateway['name'])->toBe('hardimpactdev/orbit-gateway')
            ->and($gateway['version'])->toBe('1.2.3')
            ->and($gateway['require']['hardimpactdev/orbit-core'])->toBe('1.2.3')
            ->and($gateway)->not->toHaveKey('repositories')
            ->and("{$root}/gateway/composer.lock")->not->toBeFile();
    } finally {
        (new Process(['rm', '-rf', $root]))->run();
    }
});
