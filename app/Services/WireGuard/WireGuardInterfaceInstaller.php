<?php

declare(strict_types=1);

namespace App\Services\WireGuard;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class WireGuardInterfaceInstaller
{
    public function install(string $config, string $interface = 'wg-orbit'): void
    {
        if (! preg_match('/^[a-zA-Z0-9_.-]+$/', $interface)) {
            throw new RuntimeException("Invalid WireGuard interface name: {$interface}");
        }

        $path = "/etc/wireguard/{$interface}.conf";

        $this->runRequired('sudo mkdir -p /etc/wireguard', 'create WireGuard config directory');
        $this->runRequiredWithInput("sudo tee {$path} > /dev/null", $config, 'write WireGuard config');
        $this->runRequired("sudo chmod 600 {$path}", 'secure WireGuard config');

        Process::timeout(30)->run("sudo wg-quick down {$interface}");

        $this->runRequired("sudo wg-quick up {$interface}", 'start WireGuard interface');
        $this->runRequired("sudo systemctl enable wg-quick@{$interface}", 'enable WireGuard interface');
    }

    private function runRequired(string $command, string $step): void
    {
        $result = Process::timeout(30)->run($command);

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Failed to {$step}: ".$this->output($result->errorOutput(), $result->output()));
    }

    private function runRequiredWithInput(string $command, string $input, string $step): void
    {
        $result = Process::timeout(30)->input($input)->run($command);

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Failed to {$step}: ".$this->output($result->errorOutput(), $result->output()));
    }

    private function output(string $errorOutput, string $output): string
    {
        $message = trim($errorOutput.' '.$output);

        return $message !== '' ? $message : 'unknown error';
    }
}
