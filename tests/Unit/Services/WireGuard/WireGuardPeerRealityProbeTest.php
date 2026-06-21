<?php

declare(strict_types=1);

use App\Services\WireGuard\WireGuardPeerRealityProbe;
use Illuminate\Support\Facades\Process;

it('parses WireGuard allowed IP output by public key', function (): void {
    $peers = (new WireGuardPeerRealityProbe)->parseAllowedIps(<<<'OUTPUT'
        gateway-public-key	10.6.0.2/32
        control-public-key	10.6.0.3/32 fd00::3/128

        malformed
        app-public-key     10.6.0.4/32
        OUTPUT);

    expect(array_keys($peers))->toBe([
        'gateway-public-key',
        'control-public-key',
        'app-public-key',
    ]);

    expect($peers['control-public-key']->allowedIps)->toBe([
        '10.6.0.3/32',
        'fd00::3/128',
    ]);
    expect($peers['control-public-key']->allowedAddresses)->toBe([
        '10.6.0.3',
        'fd00::3',
    ]);
});

it('reads live WireGuard peer reality from the configured interface', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.4/32\n"),
    ]);

    $peers = (new WireGuardPeerRealityProbe)->peers();

    expect($peers)->toHaveKey('app-public-key');
    expect($peers['app-public-key']->allowedAddresses)->toBe(['10.6.0.4']);
    Process::assertRan('sudo wg show wg-orbit allowed-ips');
});

it('rejects invalid interface names', function (): void {
    Process::fake();
    Process::preventStrayProcesses();

    expect(fn () => (new WireGuardPeerRealityProbe)->peers('wg-orbit; rm -rf /'))
        ->toThrow(RuntimeException::class, 'Invalid WireGuard interface name');

    Process::assertNothingRan();
});

it('fails when WireGuard peer reality cannot be read', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        'sudo wg show wg-orbit allowed-ips' => Process::result(errorOutput: 'Operation not permitted', exitCode: 1),
    ]);

    expect(fn () => (new WireGuardPeerRealityProbe)->peers())
        ->toThrow(RuntimeException::class, 'Failed to read WireGuard peer reality: Operation not permitted');
});
