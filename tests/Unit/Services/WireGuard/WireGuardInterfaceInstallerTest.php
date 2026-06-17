<?php

declare(strict_types=1);

namespace Tests\Unit\Services\WireGuard;

use App\Services\WireGuard\WireGuardInterfaceInstaller;
use Illuminate\Support\Facades\Process;
use RuntimeException;

describe('interface installer', function (): void {
    it('writes config and enables the wireguard interface', function (): void {
        $writtenConfig = null;

        Process::fake(function ($process) use (&$writtenConfig) {
            if (str_contains($process->command, 'tee /etc/wireguard/wg-orbit.conf')) {
                $writtenConfig = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(WireGuardInterfaceInstaller::class)->install("[Interface]\nPrivateKey = private\n");

        expect($writtenConfig)->toBe("[Interface]\nPrivateKey = private\n");

        Process::assertRan('sudo mkdir -p /etc/wireguard');
        Process::assertRan('sudo tee /etc/wireguard/wg-orbit.conf > /dev/null');
        Process::assertRan('sudo chmod 600 /etc/wireguard/wg-orbit.conf');
        Process::assertRan('sudo wg-quick down wg-orbit');
        Process::assertRan('sudo wg-quick up wg-orbit');
        Process::assertRan('sudo systemctl enable wg-quick@wg-orbit');
    });

    it('throws when the interface cannot be started', function (): void {
        Process::fake(function ($process) {
            if ($process->command === 'sudo wg-quick up wg-orbit') {
                return Process::result(errorOutput: 'wg-quick failed', exitCode: 1);
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        expect(fn () => app(WireGuardInterfaceInstaller::class)->install("[Interface]\n"))
            ->toThrow(RuntimeException::class, 'Failed to start WireGuard interface');
    });

    it('rejects invalid interface names before running processes', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        expect(fn () => app(WireGuardInterfaceInstaller::class)->install("[Interface]\n", 'wg orbit'))
            ->toThrow(RuntimeException::class, 'Invalid WireGuard interface name');

        Process::assertRanTimes(fn (): bool => true, 0);
    });
});
