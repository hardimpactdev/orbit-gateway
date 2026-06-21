<?php

declare(strict_types=1);

use App\Contracts\ProgressReporter;
use App\Services\Tools\ToolDefinitionRegistry;

function gatewayRelocationRepoRoot(): string
{
    $basePath = base_path();

    if (basename($basePath) === 'gateway' && basename(dirname($basePath)) === 'apps') {
        return dirname($basePath, 2);
    }

    return $basePath;
}

it('keeps the Laravel gateway app under apps gateway', function (): void {
    $repoRoot = gatewayRelocationRepoRoot();
    $gatewayRoot = "{$repoRoot}/apps/gateway";

    expect($gatewayRoot)->toBeDirectory()
        ->and("{$gatewayRoot}/artisan")->toBeFile()
        ->and("{$gatewayRoot}/.env.live.example")->toBeFile()
        ->and("{$gatewayRoot}/app")->toBeDirectory()
        ->and("{$gatewayRoot}/bootstrap")->toBeDirectory()
        ->and("{$gatewayRoot}/config")->toBeDirectory()
        ->and("{$gatewayRoot}/database")->toBeDirectory()
        ->and("{$gatewayRoot}/public")->toBeDirectory()
        ->and("{$gatewayRoot}/routes")->toBeDirectory()
        ->and("{$gatewayRoot}/resources")->toBeDirectory()
        ->and("{$gatewayRoot}/storage")->toBeDirectory()
        ->and("{$gatewayRoot}/tests")->toBeDirectory()
        ->and("{$gatewayRoot}/boost.json")->toBeFile()
        ->and("{$gatewayRoot}/package.json")->toBeFile()
        ->and("{$gatewayRoot}/pint.json")->toBeFile()
        ->and("{$gatewayRoot}/phpstan.neon")->toBeFile()
        ->and("{$gatewayRoot}/rector.php")->toBeFile()
        ->and("{$gatewayRoot}/vite.config.js")->toBeFile()
        ->and("{$repoRoot}/app")->not->toBeDirectory()
        ->and("{$repoRoot}/bootstrap")->not->toBeDirectory()
        ->and("{$repoRoot}/config")->not->toBeDirectory()
        ->and("{$repoRoot}/database")->not->toBeDirectory()
        ->and("{$repoRoot}/public")->not->toBeDirectory()
        ->and("{$repoRoot}/routes")->not->toBeDirectory()
        ->and("{$repoRoot}/resources")->not->toBeDirectory()
        ->and("{$repoRoot}/storage")->not->toBeDirectory()
        ->and("{$repoRoot}/tests")->not->toBeDirectory()
        ->and("{$repoRoot}/artisan")->not->toBeFile()
        ->and("{$repoRoot}/.env.live.example")->not->toBeFile()
        ->and("{$repoRoot}/pint.json")->not->toBeFile()
        ->and("{$repoRoot}/.npmrc")->not->toBeFile()
        ->and("{$repoRoot}/phpunit.xml")->not->toBeFile();
});

it('keeps root composer as an orchestrator without gateway autoloads', function (): void {
    $repoRoot = gatewayRelocationRepoRoot();
    $composer = json_decode(
        (string) file_get_contents("{$repoRoot}/composer.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer)
        ->type->toBe('project')
        ->not->toHaveKeys(['autoload', 'autoload-dev', 'extra'])
        ->and($composer['require'])->toBe(['php' => '^8.5'])
        ->and($composer['scripts'])->toHaveKeys([
            'test',
            'docs-lint',
            'quality-check',
            'test:e2e',
        ]);
});

it('keeps quality tool configs local to each app and package', function (): void {
    $repoRoot = gatewayRelocationRepoRoot();

    foreach (['apps/gateway', 'apps/cli', 'apps/docs', 'apps/e2e', 'packages/core'] as $projectPath) {
        expect("{$repoRoot}/{$projectPath}/pint.json")->toBeFile()
            ->and("{$repoRoot}/{$projectPath}/phpstan.neon")->toBeFile()
            ->and("{$repoRoot}/{$projectPath}/rector.php")->toBeFile();
    }

    expect("{$repoRoot}/pint.json")->not->toBeFile()
        ->and("{$repoRoot}/phpstan.neon")->not->toBeFile()
        ->and("{$repoRoot}/rector.php")->not->toBeFile();
});

it('uses the gateway app autoloader directly', function (): void {
    $repoRoot = gatewayRelocationRepoRoot();
    $gatewayArtisanPath = "{$repoRoot}/apps/gateway/artisan";
    $gatewayArtisan = file_exists($gatewayArtisanPath) ? (file_get_contents($gatewayArtisanPath) ?: '') : '';

    expect("{$repoRoot}/artisan")->not->toBeFile()
        ->and($gatewayArtisan)->toContain("__DIR__.'/vendor/autoload.php'")
        ->and($gatewayArtisan)->not->toContain('/../../vendor/autoload.php');
});

it('registers the relocated gateway bootstrap for Laravel parallel tests', function (): void {
    $repoRoot = gatewayRelocationRepoRoot();
    $pestConfig = file_get_contents("{$repoRoot}/apps/gateway/tests/Pest.php") ?: '';

    expect($pestConfig)
        ->toContain('ParallelRunner::resolveApplicationUsing')
        ->toContain("__DIR__.'/../bootstrap/app.php'")
        ->toContain('Kernel::class');
});

it('registers gateway providers from the relocated bootstrap directory', function (): void {
    $repoRoot = gatewayRelocationRepoRoot();
    $gatewayBootstrap = file_get_contents("{$repoRoot}/apps/gateway/bootstrap/app.php") ?: '';

    expect($gatewayBootstrap)
        ->toContain("__DIR__.'/providers.php'")
        ->and(app()->bound(ProgressReporter::class))->toBeTrue()
        ->and(app()->bound(ToolDefinitionRegistry::class))->toBeTrue();
});

it('points PHPStan at a bootstrap file for the relocated gateway app', function (): void {
    $repoRoot = gatewayRelocationRepoRoot();
    $phpstanConfig = file_get_contents("{$repoRoot}/apps/gateway/phpstan.neon") ?: '';
    $phpstanBootstrap = file_get_contents("{$repoRoot}/apps/gateway/bootstrap/phpstan.php") ?: '';

    expect($phpstanConfig)
        ->toContain('bootstrap/phpstan.php')
        ->toContain('app')
        ->toContain('config')
        ->toContain('database')
        ->not->toContain('apps/gateway/')
        ->and($phpstanBootstrap)->toContain("__DIR__.'/app.php'")
        ->and($phpstanBootstrap)->toContain('LARAVEL_VERSION');
});
