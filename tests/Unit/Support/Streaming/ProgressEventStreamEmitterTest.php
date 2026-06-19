<?php

declare(strict_types=1);

use App\Support\Streaming\ProgressEventStreamEmitter;

it('emits progress events as server sent event frames', function (): void {
    $emitter = new ProgressEventStreamEmitter('cli');

    ob_start();
    $emitter->tree('Workspace - feature-docs', [
        ['key' => 'create', 'label' => 'Create workspace', 'doneLabel' => 'Created workspace'],
    ]);
    $emitter->stepEvent('create', 'start');
    $emitter->stepEvent('create', 'done', 'feature-docs');
    $emitter->complete(0, ['workspace' => ['name' => 'feature-docs']]);
    $output = (string) ob_get_clean();

    expect($output)->toContain("event: tree\n")
        ->and($output)->toContain('"title":"Workspace - feature-docs"')
        ->and($output)->toContain("event: step\n")
        ->and($output)->toContain('"status":"start"')
        ->and($output)->toContain('"status":"done"')
        ->and($output)->toContain("event: complete\n")
        ->and($output)->toContain('"exit_code":0')
        ->and($output)->toContain('"workspace":{"name":"feature-docs"}');
});

it('emits heartbeat comments', function (): void {
    $emitter = new ProgressEventStreamEmitter('cli');

    ob_start();
    $emitter->heartbeat();
    $output = (string) ob_get_clean();

    expect($output)->toBe(": heartbeat\n\n");
});

it('flushes output under fpm-fcgi, cli-server, and frankenphp sapi', function (string $sapi): void {
    $emitter = new ProgressEventStreamEmitter($sapi);
    $flushedOutput = '';

    ob_start(function (string $chunk) use (&$flushedOutput): string {
        $flushedOutput .= $chunk;

        return '';
    });

    try {
        $emitter->tree('Test', [['key' => 'step', 'label' => 'Step']]);
    } finally {
        ob_end_clean();
    }

    expect($flushedOutput)->toContain("event: tree\n")
        ->and($flushedOutput)->toContain('"title":"Test"');
})->with(['fpm-fcgi', 'cli-server', 'frankenphp']);

it('skips flush under cli sapi', function (): void {
    $emitter = new ProgressEventStreamEmitter('cli');

    ob_start();
    $emitter->tree('Test', [['key' => 'step', 'label' => 'Step']]);
    $output = (string) ob_get_clean();

    expect($output)->toContain("event: tree\n")
        ->and($output)->toContain('"title":"Test"');
});
