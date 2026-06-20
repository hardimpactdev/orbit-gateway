<?php

declare(strict_types=1);

use App\Contracts\ProgressReporter;
use App\Support\Streaming\NullProgressReporter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use App\Support\Streaming\SseProgressReporter;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('binds an sse progress reporter while streaming and restores the null reporter', function (): void {
    $response = (new ProgressEventStreamResponseFactory)->make(function (): void {
        $reporter = app(ProgressReporter::class);

        expect($reporter)->toBeInstanceOf(SseProgressReporter::class);

        $reporter->tree('Workspace - feature-docs', [
            ['key' => 'create', 'label' => 'Create workspace'],
        ]);
        $reporter->stepStart('create');
        $reporter->stepDone('create', 'feature-docs');
    });

    ob_start();
    $response->sendContent();
    $output = (string) ob_get_clean();

    expect($response->headers->get('Content-Type'))->toBe('text/event-stream')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($output)->toContain('event: tree')
        ->and($output)->toContain('"status":"start"')
        ->and($output)->toContain('"status":"done"')
        ->and(app(ProgressReporter::class))->toBeInstanceOf(NullProgressReporter::class);
});

it('turns stream exceptions into error events and restores the null reporter', function (): void {
    Log::spy();

    $response = (new ProgressEventStreamResponseFactory)->make(function (): void {
        throw new RuntimeException('stream exploded');
    });

    ob_start();
    $response->sendContent();
    $output = (string) ob_get_clean();

    expect($output)->toContain('event: error')
        ->and($output)->toContain('"message":"stream exploded"')
        ->and(app(ProgressReporter::class))->toBeInstanceOf(NullProgressReporter::class);

    Log::shouldHaveReceived('error')->once();
});

it('flushes output buffers under fpm-fcgi, cli-server, and frankenphp sapi', function (string $sapi): void {
    $response = (new ProgressEventStreamResponseFactory($sapi))->make(function (): void {
        $reporter = app(ProgressReporter::class);

        expect($reporter)->toBeInstanceOf(SseProgressReporter::class);

        $reporter->tree('Test', [['key' => 'step', 'label' => 'Step']]);
        $reporter->stepStart('step');
        $reporter->stepDone('step');
    });

    $flushedOutput = '';

    ob_start(function (string $chunk) use (&$flushedOutput): string {
        $flushedOutput .= $chunk;

        return '';
    });

    try {
        $response->sendContent();
    } finally {
        ob_end_clean();
    }

    expect($flushedOutput)->toContain('event: tree')
        ->and($flushedOutput)->toContain('"status":"done"');
})->with(['fpm-fcgi', 'cli-server', 'frankenphp']);

it('skips buffer flush under cli sapi', function (): void {
    $response = (new ProgressEventStreamResponseFactory('cli'))->make(function (): void {
        $reporter = app(ProgressReporter::class);
        $reporter->tree('Test', [['key' => 'step', 'label' => 'Step']]);
        $reporter->stepStart('step');
        $reporter->stepDone('step');
    });

    ob_start();
    $response->sendContent();
    $output = (string) ob_get_clean();

    expect($output)->toContain('event: tree')
        ->and($output)->toContain('"status":"done"');
});
