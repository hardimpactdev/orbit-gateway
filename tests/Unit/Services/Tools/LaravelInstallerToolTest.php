<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
use App\Tools\LaravelInstallerTool;
use Tests\TestCase;

uses(TestCase::class);

describe('LaravelInstallerTool', function (): void {
    it('has slug laravel-installer and category runtime', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->slug())->toBe('laravel-installer')
            ->and($tool->category())->toBe('runtime');
    });

    it('declares install, update, and remove capabilities', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->capabilities())->toContain('install')
            ->and($tool->capabilities())->toContain('update')
            ->and($tool->capabilities())->toContain('remove');
    });

    it('installScript runs composer global require laravel/installer', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->installScript())->toContain('composer global require laravel/installer');
    });

    it('installScript symlinks the laravel binary into /usr/local/bin/laravel', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->installScript())->toContain('/usr/local/bin/laravel');
    });

    it('installScript runs as the configured managed system user', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->installScript(['managed_user' => 'nckrtl']))
            ->toContain("MANAGED_USER='nckrtl'")
            ->toContain('COMPOSER_HOME="/home/${MANAGED_USER}/.config/composer"')
            ->toContain('sudo -u "${MANAGED_USER}"');
    });

    it('configures Composer and gh auth from a staged GitHub token file', function (): void {
        $tool = new LaravelInstallerTool;
        $script = $tool->installScript(['github_token_file' => '/tmp/orbit-secret.github']);

        expect($script)
            ->toContain("GITHUB_TOKEN_FILE='/tmp/orbit-secret.github'")
            ->toContain('github-oauth')
            ->toContain('${COMPOSER_HOME}/auth.json')
            ->toContain('gh auth login --hostname github.com --with-token')
            ->toContain('gh auth setup-git --hostname github.com');
    });

    it('updateScript runs composer global update laravel/installer', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->updateScript())->toContain('composer global update laravel/installer');
    });

    it('removeScript runs composer global remove laravel/installer', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->removeScript())->toContain('composer global remove laravel/installer');
    });

    it('removeScript removes the /usr/local/bin/laravel symlink', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->removeScript())->toContain('/usr/local/bin/laravel');
    });

    it('probeMetadata identifies the laravel binary', function (): void {
        $tool = new LaravelInstallerTool;
        $metadata = $tool->probeMetadata();

        expect($metadata['binary'])->toBe('/usr/local/bin/laravel');
    });

    it('probeMetadata runs the installer version command from the system path', function (): void {
        $tool = new LaravelInstallerTool;
        $metadata = $tool->probeMetadata();

        expect($metadata['version_command'])->toBe('/usr/local/bin/laravel --version');
    });

    it('is resolvable by slug from the tool catalog', function (): void {
        $catalog = app(ToolCatalog::class);

        expect($catalog->definition('laravel-installer'))->toBeInstanceOf(LaravelInstallerTool::class);
    });
});
