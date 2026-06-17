<?php

declare(strict_types=1);

use App\Support\Cli\RemoteProgressRenderer;
use Symfony\Component\Console\Output\BufferedOutput;

it('uses the canonical spinner frame order for active remote progress steps', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new RemoteProgressRenderer($output);

    $renderer->tree('Creating Workspace', [
        [
            'key' => 'setup',
            'label' => 'Run workspace setup steps',
            'doneLabel' => 'Ran workspace setup steps',
        ],
    ]);

    $renderer->step('setup', 'start');
    $renderer->tick();

    preg_match_all('/[○◉]\s+Run workspace setup steps/u', $output->fetch(), $matches);

    expect(array_slice($matches[0], -2))->toBe([
        '○  Run workspace setup steps',
        '◉  Run workspace setup steps',
    ]);
});

it('renders progress messages for active remote progress steps', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new RemoteProgressRenderer($output);

    $renderer->tree('Setting Up Workspace', [
        [
            'key' => 'setup',
            'label' => 'Run workspace setup steps',
            'doneLabel' => 'Ran workspace setup steps',
        ],
    ]);

    $renderer->step('setup', 'start');
    $renderer->step('setup', 'progress', 'Running setup step 1/2: composer install --no-interaction');

    expect($output->fetch())
        ->toContain('Run workspace setup steps')
        ->toContain('Running setup step 1/2: composer install --no-interaction');
});

it('keeps progress messages visible across spinner ticks', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new RemoteProgressRenderer($output);

    $renderer->tree('Setting Up Workspace', [
        [
            'key' => 'setup',
            'label' => 'Run workspace setup steps',
            'doneLabel' => 'Ran workspace setup steps',
        ],
    ]);

    $renderer->step('setup', 'start');
    $renderer->step('setup', 'progress', 'Running setup step 1/2: composer install');
    $renderer->tick();

    $lines = array_values(array_filter(explode("\n", $output->fetch())));

    expect(end($lines))->toContain('Running setup step 1/2: composer install');
});
