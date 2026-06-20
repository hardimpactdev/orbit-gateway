<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('does not pass env options into remote shell callers', function (): void {
    $offenders = [];
    $directory = new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($directory);

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (preg_match('/[\'"]env[\'"]\s*=>/', $contents) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
