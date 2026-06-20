<?php

declare(strict_types=1);

it('builds native cli binaries from a compressed no-dev phar staging directory', function (): void {
    $scriptPath = repo_path('bin/orbit-build-cli-binary');

    expect($scriptPath)->toBeFile();

    $script = file_get_contents($scriptPath);

    expect($script)
        ->toContain('mktemp -d')
        ->toContain('rsync -a')
        ->toContain('composer --working-dir="$stage_cli_dir" install')
        ->toContain('--no-dev')
        ->toContain('--optimize-autoloader')
        ->toContain('php orbit app:build orbit.phar')
        ->toContain('compressFiles(Phar::GZ)')
        ->toContain('vendor/bin/phpacker build "$platform" "$architecture"')
        ->toContain('--src=./builds/orbit.phar')
        ->not->toContain('composer --working-dir="$cli_dir" install --no-dev');
});

it('fails clearly when the host php runtime cannot compress phars', function (): void {
    $scriptPath = repo_path('bin/orbit-build-cli-binary');

    expect($scriptPath)->toBeFile();

    expect(file_get_contents($scriptPath))
        ->toContain('extension_loaded("zlib")')
        ->toContain('Host PHP must have the zlib extension');
});
