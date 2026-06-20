<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

describe('orbit:internal:detect-platform', function (): void {
    it('prints the detected platform', function (): void {
        Process::fake([
            'sw_vers -productVersion' => Process::result(output: "15.4\n"),
            'cat /etc/os-release' => Process::result(output: "ID=ubuntu\nVERSION_ID=\"24.04\"\n"),
        ]);

        $exitCode = Artisan::call('orbit:internal:detect-platform');

        expect($exitCode)->toBe(0)
            ->and(trim(Artisan::output()))->toBe(match (PHP_OS_FAMILY) {
                'Darwin' => 'macos_15-4',
                'Linux' => 'ubuntu_24-04',
                default => '',
            });
    });

    it('updates the local active node when requested', function (): void {
        Process::fake([
            'sw_vers -productVersion' => Process::result(output: "15.4\n"),
            'cat /etc/os-release' => Process::result(output: "ID=ubuntu\nVERSION_ID=\"24.04\"\n"),
        ]);

        $node = Node::factory()->gateway()->create([
            'name' => 'gateway-1',
            'platform' => 'unknown',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
        ]);

        $exitCode = Artisan::call('orbit:internal:detect-platform', [
            '--update-local-node' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($node->fresh()->platform)->toBe(match (PHP_OS_FAMILY) {
                'Darwin' => 'macos_15-4',
                'Linux' => 'ubuntu_24-04',
                default => 'unknown',
            });
    });
});
