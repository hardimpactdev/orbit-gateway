<?php

declare(strict_types=1);

use App\Services\Gateway\GatewayExposureTransitionGuard;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

function gatewayExposureTransitionCheckCommands(): array
{
    return [
        'tcp/80' => "sudo ss -H -ltn 'sport = :80' | grep -q .",
        'tcp/443' => "sudo ss -H -ltn 'sport = :443' | grep -q .",
        'udp/443' => "sudo ss -H -lun 'sport = :443' | grep -q .",
    ];
}

it('passes when the full public exposure port set is released', function (): void {
    Process::fake(array_fill_keys(
        array_values(gatewayExposureTransitionCheckCommands()),
        Process::result(exitCode: 1),
    ));

    (new GatewayExposureTransitionGuard)->assertPublicPortsReleased();

    foreach (gatewayExposureTransitionCheckCommands() as $command) {
        Process::assertRan($command);
    }
});

it('fails when a required public exposure port is still occupied', function (string $port): void {
    $commands = gatewayExposureTransitionCheckCommands();
    $fakes = [];

    foreach ($commands as $name => $command) {
        $fakes[$command] = Process::result(exitCode: $name === $port ? 0 : 1);
    }

    Process::fake($fakes);

    expect(fn () => (new GatewayExposureTransitionGuard)->assertPublicPortsReleased())
        ->toThrow(RuntimeException::class, "Gateway exposure transition cannot continue because {$port} is still in use.");
})->with([
    'tcp 80' => ['tcp/80'],
    'tcp 443' => ['tcp/443'],
    'udp 443' => ['udp/443'],
]);
