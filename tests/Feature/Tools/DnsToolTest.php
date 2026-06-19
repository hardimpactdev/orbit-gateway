<?php

declare(strict_types=1);

use App\Tools\DnsTool;

it('emits install script that invokes the internal orbit-dns installer', function (): void {
    $script = (new DnsTool)->installScript();

    expect($script)->toContain('orbit:internal:install-orbit-dns');
});

it('emits remove script that stops the orbit-dns container via compose', function (): void {
    $script = (new DnsTool)->removeScript();

    expect($script)->toContain('docker compose')
        ->and($script)->toContain('orbit-dns')
        ->and($script)->toContain('stop')
        ->and($script)->toContain('rm -f');
});

it('emits update script equivalent to install (re-runs the installer)', function (): void {
    $tool = new DnsTool;

    expect($tool->updateScript())->toBe($tool->installScript());
});

it('advertises required capabilities for gateway DNS infrastructure', function (): void {
    $tool = new DnsTool;

    expect($tool->capabilities())->toContain('safe-fix')
        ->and($tool->capabilities())->toContain('safe-adopt')
        ->and($tool->capabilities())->toContain('update');
});
