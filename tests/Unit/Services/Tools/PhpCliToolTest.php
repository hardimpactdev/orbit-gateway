<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
use App\Tools\PhpCliTool;
use Tests\TestCase;

uses(TestCase::class);

describe('PhpCliTool', function (): void {
    it('has the correct slug and category', function (): void {
        $tool = new PhpCliTool;

        expect($tool->slug())->toBe('php-cli')
            ->and($tool->category())->toBe('runtime');
    });

    it('declares install, update, and safe-adopt capabilities', function (): void {
        $tool = new PhpCliTool;

        expect($tool->capabilities())->toContain('install')
            ->and($tool->capabilities())->toContain('update')
            ->and($tool->capabilities())->toContain('safe-adopt');
    });

    it('installScript downloads from dl.static-php.dev bulk preset', function (): void {
        $tool = new PhpCliTool;

        expect($tool->installScript())->toContain('dl.static-php.dev')
            ->and($tool->installScript())->toContain('bulk');
    });

    it('retries transient static PHP download failures', function (): void {
        $tool = new PhpCliTool;

        expect($tool->installScript())
            ->toContain('curl -fsSL --retry 5 --retry-delay 2 --retry-all-errors')
            ->and($tool->updateScript())->toContain('curl -fsSL --retry 5 --retry-delay 2 --retry-all-errors');
    });

    it('installScript includes pinned patch versions for all supported minors', function (): void {
        $tool = new PhpCliTool;
        $script = $tool->installScript();

        expect($script)->toContain('8.5.6')
            ->and($script)->toContain('8.4.21')
            ->and($script)->toContain('8.3.31');
    });

    it('installScript installs binaries under /opt/orbit/php', function (): void {
        $tool = new PhpCliTool;

        expect($tool->installScript())->toContain('/opt/orbit/php');
    });

    it('installScript detects OS with uname -s', function (): void {
        $tool = new PhpCliTool;

        expect($tool->installScript())->toContain('uname -s');
    });

    it('installScript detects architecture with uname -m', function (): void {
        $tool = new PhpCliTool;

        expect($tool->installScript())->toContain('uname -m');
    });

    it('installScript creates the /usr/local/bin/php default symlink', function (): void {
        $tool = new PhpCliTool;

        expect($tool->installScript())->toContain('/usr/local/bin/php');
    });

    it('installScript is idempotent and uses set -e', function (): void {
        $tool = new PhpCliTool;

        expect($tool->installScript())->toContain('set -e');
    });

    it('installScript does not contain ondrej PPA or add-apt-repository', function (): void {
        $tool = new PhpCliTool;
        $script = $tool->installScript();

        expect($script)->not->toContain('ppa:ondrej')
            ->and($script)->not->toContain('add-apt-repository');
    });

    it('updateScript also downloads from dl.static-php.dev', function (): void {
        $tool = new PhpCliTool;

        expect($tool->updateScript())->toContain('dl.static-php.dev');
    });

    it('updateScript does not contain apt only-upgrade logic', function (): void {
        $tool = new PhpCliTool;

        expect($tool->updateScript())->not->toContain('--only-upgrade');
    });

    it('probeMetadata identifies the Orbit-managed php binary', function (): void {
        $tool = new PhpCliTool;
        $metadata = $tool->probeMetadata();

        expect($metadata['binary'])->toBe('/opt/orbit/php/8.5/bin/php')
            ->and($metadata['version_command'])->toBe('/opt/orbit/php/8.5/bin/php --version')
            ->and($metadata['version_command'])->not->toContain('php -r');
    });

    it('is resolvable by slug from the tool catalog', function (): void {
        $catalog = app(ToolCatalog::class);

        expect($catalog->definition('php-cli'))->toBeInstanceOf(PhpCliTool::class);
    });
});
