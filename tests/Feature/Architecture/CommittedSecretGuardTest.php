<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('does not commit Laravel app key material', function (): void {
    $files = Process::path(repo_path())->run(['git', 'ls-files', '-z']);

    expect($files->successful())->toBeTrue();

    $offenders = collect(explode("\0", $files->output()))
        ->filter()
        ->filter(fn (string $path): bool => is_file(repo_path($path)))
        ->flatMap(function (string $path): array {
            $contents = file_get_contents(repo_path($path));

            if (! is_string($contents)) {
                return [];
            }

            return preg_match('/(?:APP_KEY=|name="APP_KEY" value=")base64:[A-Za-z0-9+\/=]{20,}/', $contents) === 1
                ? [$path]
                : [];
        })
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
