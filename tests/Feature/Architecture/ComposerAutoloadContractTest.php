<?php

declare(strict_types=1);

it('keeps pest test files out of composer optimized classmaps', function (): void {
    $composer = json_decode(
        (string) file_get_contents(repo_path('apps/gateway/composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $excludeFromClassmap = $composer['autoload-dev']['exclude-from-classmap'] ?? [];

    expect($excludeFromClassmap)->toContain('/tests/');
});
