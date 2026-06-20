<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
use App\Tools\ComposerTool;
use Tests\TestCase;

uses(TestCase::class);

describe('ComposerTool', function (): void {
    it('has the correct slug and category', function (): void {
        $tool = new ComposerTool;

        expect($tool->slug())->toBe('composer')
            ->and($tool->category())->toBe('runtime');
    });

    it('declares install, update, and safe-adopt capabilities', function (): void {
        $tool = new ComposerTool;

        expect($tool->capabilities())->toContain('install')
            ->and($tool->capabilities())->toContain('update')
            ->and($tool->capabilities())->toContain('safe-adopt');
    });

    it('installScript downloads from the official getcomposer.org installer URL', function (): void {
        $tool = new ComposerTool;

        expect($tool->installScript())->toContain('getcomposer.org/installer');
    });

    it('installScript uses the static default php binary, not a versioned one', function (): void {
        $tool = new ComposerTool;
        $script = $tool->installScript();

        expect($script)->toContain('php ')
            ->and($script)->not->toContain('php8.5')
            ->and($script)->not->toContain('php8.4');
    });

    it('installScript performs the official integrity check against composer.github.io', function (): void {
        $tool = new ComposerTool;
        $script = $tool->installScript();

        expect($script)->toContain('composer.github.io/installer.sig')
            ->and($script)->toContain('sha384sum composer-setup.php')
            ->and($script)->not->toContain('php -r');
    });

    it('installScript installs the phar to /usr/local/bin with filename composer', function (): void {
        $tool = new ComposerTool;
        $script = $tool->installScript();

        expect($script)->toContain('--install-dir=/usr/local/bin')
            ->and($script)->toContain('--filename=composer');
    });

    it('installScript fails loudly on hash mismatch', function (): void {
        $tool = new ComposerTool;

        expect($tool->installScript())->toContain('exit 1');
    });

    it('installScript uses set -e', function (): void {
        $tool = new ComposerTool;

        expect($tool->installScript())->toContain('set -e');
    });

    it('probeMetadata identifies the Orbit-managed composer binary', function (): void {
        $tool = new ComposerTool;
        $metadata = $tool->probeMetadata();

        expect($metadata['binary'])->toBe('/usr/local/bin/composer')
            ->and($metadata['version_command'])->toBe('/usr/local/bin/composer --version')
            ->and($metadata['update_command'])->toBe('sudo /usr/local/bin/composer self-update 2>/dev/null');
    });

    it('is resolvable by slug from the tool catalog', function (): void {
        $catalog = app(ToolCatalog::class);

        expect($catalog->definition('composer'))->toBeInstanceOf(ComposerTool::class);
    });
});
