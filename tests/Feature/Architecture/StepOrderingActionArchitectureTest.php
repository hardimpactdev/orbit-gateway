<?php

declare(strict_types=1);

it('keeps ordered step mutation logic out of Eloquent models', function (): void {
    $modelSources = [
        'apps/gateway/app/Models/DeployStep.php',
        'apps/gateway/app/Models/WorkspaceStep.php',
    ];

    foreach ($modelSources as $path) {
        $source = file_get_contents(repo_path($path)) ?: '';

        expect($source)
            ->not->toContain('DB::transaction')
            ->not->toContain('createOrdered(')
            ->not->toContain('deleteAndCompact(');
    }
});
