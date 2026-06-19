<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class GatewayExposureTransitionGuard
{
    /**
     * @var array<string, string>
     */
    private const array PortChecks = [
        'tcp/80' => "sudo ss -H -ltn 'sport = :80' | grep -q .",
        'tcp/443' => "sudo ss -H -ltn 'sport = :443' | grep -q .",
        'udp/443' => "sudo ss -H -lun 'sport = :443' | grep -q .",
    ];

    public function assertPublicPortsReleased(): void
    {
        foreach (self::PortChecks as $port => $command) {
            $this->assertPortReleased($port, $command);
        }
    }

    private function assertPortReleased(string $port, string $command): void
    {
        $result = Process::timeout(15)->run($command);

        if ($result->exitCode() === 1) {
            return;
        }

        if ($result->successful()) {
            throw new RuntimeException("Gateway exposure transition cannot continue because {$port} is still in use.");
        }

        $message = trim($result->errorOutput().' '.$result->output());

        throw new RuntimeException("Failed to inspect {$port} before gateway exposure transition: ".($message !== '' ? $message : 'unknown error'));
    }
}
