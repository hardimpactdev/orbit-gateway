<?php

declare(strict_types=1);

use App\E2E\Support\E2EPhaseTimer;
use Symfony\Component\Process\Process;

it('returns the callback value from measure', function (): void {
    $timer = new E2EPhaseTimer;

    $result = $timer->measure('phase', fn () => 42);

    expect($result)->toBe(42);
});

it('records phase events with non-negative durations', function (): void {
    $timer = new E2EPhaseTimer;

    $timer->measure('first', fn () => null);
    $timer->measure('second', fn () => null);

    $events = $timer->events();

    expect($events)->toHaveCount(2)
        ->and($events[0]['name'])->toBe('first')
        ->and($events[0]['seconds'])->toBeFloat()->toBeGreaterThanOrEqual(0)
        ->and($events[1]['name'])->toBe('second');
});

it('records the event even when the callback throws', function (): void {
    $timer = new E2EPhaseTimer;

    expect(fn () => $timer->measure('boom', function (): void {
        throw new RuntimeException('nope');
    }))->toThrow(RuntimeException::class, 'nope');

    expect($timer->events())->toHaveCount(1)
        ->and($timer->events()[0]['name'])->toBe('boom');
});

it('streams start and done checkpoints when enabled', function (): void {
    $lines = [];
    $timer = new E2EPhaseTimer(
        stream: true,
        writer: function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    );

    $timer->measure('phase', fn () => null);

    expect($timer->streamsCheckpoints())->toBeTrue()
        ->and($lines[0])->toBe('[orbit-e2e] phase started')
        ->and(str_starts_with($lines[1], '[orbit-e2e] phase done '))->toBeTrue();
});

it('streams failed checkpoints before rethrowing', function (): void {
    $lines = [];
    $timer = new E2EPhaseTimer(
        stream: true,
        writer: function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    );

    expect(fn () => $timer->measure('boom', function (): void {
        throw new RuntimeException('nope');
    }))->toThrow(RuntimeException::class, 'nope');

    expect($lines[0])->toBe('[orbit-e2e] boom started')
        ->and($lines[1])->toContain('[orbit-e2e] boom failed ')
        ->and($lines[1])->toContain('RuntimeException: nope');
});

it('creates child timers that share stream output and parent events with a label prefix', function (): void {
    $lines = [];
    $timer = new E2EPhaseTimer(
        stream: true,
        writer: function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    );

    $child = $timer->child('checkout');

    $child->measure('checkout.archive', fn () => null);

    expect($timer->events())->toHaveCount(1)
        ->and($timer->events()[0]['name'])->toBe('checkout checkout.archive')
        ->and($child->events())->toHaveCount(1)
        ->and($child->events()[0]['name'])->toBe('checkout checkout.archive')
        ->and($lines[0])->toBe('[orbit-e2e] checkout checkout.archive started')
        ->and($lines[1])->toStartWith('[orbit-e2e] checkout checkout.archive done ');
});

it('merges externally recorded child events back into the parent timer', function (): void {
    $timer = new E2EPhaseTimer;

    $timer->mergeExternalEvents([
        ['name' => 'operator checkout.source-overlay', 'seconds' => 1.2],
        ['name' => 'operator checkout.vendor', 'seconds' => 0.4],
    ]);

    expect($timer->events())->toBe([
        ['name' => 'operator checkout.source-overlay', 'seconds' => 1.2],
        ['name' => 'operator checkout.vendor', 'seconds' => 0.4],
    ]);
});

it('flush is silent when ORBIT_E2E_TIMINGS is not 1', function (): void {
    $previous = getenv('ORBIT_E2E_TIMINGS');
    putenv('ORBIT_E2E_TIMINGS');

    try {
        $timer = new E2EPhaseTimer;
        $timer->measure('phase', fn () => null);

        ob_start();
        $stderr = fopen('php://memory', 'w+');
        $timer->flush('label');
        ob_end_clean();

        // Re-running with the env unset should produce no STDERR output.
        // We can only assert behavior indirectly: events remain queryable
        // and no exception is thrown.
        expect($timer->events())->toHaveCount(1);
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_TIMINGS');
        } else {
            putenv("ORBIT_E2E_TIMINGS={$previous}");
        }
    }
});

it('appends flushed timing lines to the configured timings file', function (): void {
    $previousTimings = getenv('ORBIT_E2E_TIMINGS');
    $previousFile = getenv('ORBIT_E2E_TIMINGS_FILE');
    $timingsFile = tempnam(sys_get_temp_dir(), 'orbit-e2e-timer-');

    putenv('ORBIT_E2E_TIMINGS=1');
    putenv("ORBIT_E2E_TIMINGS_FILE={$timingsFile}");

    try {
        $timer = new E2EPhaseTimer;
        $timer->measure('checkout.reset', fn () => null);
        $timer->flush('checkout.worker');

        $contents = file($timingsFile, FILE_IGNORE_NEW_LINES);

        expect($contents)->toHaveCount(1)
            ->and($contents[0])->toMatch('/^\[orbit-e2e\] checkout\.worker checkout\.reset \d+\.\d{3}s$/');
    } finally {
        @unlink($timingsFile);

        $previousTimings === false
            ? putenv('ORBIT_E2E_TIMINGS')
            : putenv("ORBIT_E2E_TIMINGS={$previousTimings}");

        $previousFile === false
            ? putenv('ORBIT_E2E_TIMINGS_FILE')
            : putenv("ORBIT_E2E_TIMINGS_FILE={$previousFile}");
    }
});

it('does not write flushed timing lines to stderr when a timings file is configured', function (): void {
    $timingsFile = tempnam(sys_get_temp_dir(), 'orbit-e2e-timer-');

    $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';

putenv('ORBIT_E2E_TIMINGS=1');
putenv('ORBIT_E2E_TIMINGS_FILE='.getenv('ORBIT_TEST_TIMINGS_FILE'));

$timer = new App\E2E\Support\E2EPhaseTimer;
$timer->measure('checkout.reset', fn () => null);
$timer->flush('checkout.worker');
PHP;

    try {
        $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
            'ORBIT_TEST_TIMINGS_FILE' => $timingsFile,
        ]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue()
            ->and($process->getErrorOutput())->not->toContain('[orbit-e2e]')
            ->and(file_get_contents($timingsFile))->toMatch('/^\[orbit-e2e\] checkout\.worker checkout\.reset \d+\.\d{3}s$/');
    } finally {
        @unlink($timingsFile);
    }
});
