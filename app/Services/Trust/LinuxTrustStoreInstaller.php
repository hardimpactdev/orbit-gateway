<?php

declare(strict_types=1);

namespace App\Services\Trust;

use App\Enums\Trust\TrustStoreInstallReason;
use Closure;
use Illuminate\Support\Facades\Process;

final class LinuxTrustStoreInstaller implements TrustStoreInstaller
{
    private const int COMMAND_TIMEOUT = 30;

    public function isCaTrusted(string $rootCaPath, string $label): bool
    {
        $certName = 'orbit-gateway-ca-'.strtolower($label).'.crt';

        return is_readable("/usr/local/share/ca-certificates/{$certName}");
    }

    public function trustCa(string $rootCaPath, string $label, ?Closure $log = null): void
    {
        $certName = 'orbit-gateway-ca-'.strtolower($label).'.crt';
        $targetPath = "/usr/local/share/ca-certificates/{$certName}";

        $command = sprintf(
            'sudo cp %s %s && sudo update-ca-certificates',
            escapeshellarg($rootCaPath),
            escapeshellarg($targetPath),
        );

        if ($log !== null) {
            $log("Running: {$command}");
        }

        $result = Process::timeout(self::COMMAND_TIMEOUT)->run($command);

        if (! $result->successful()) {
            throw new TrustStoreInstallException(
                "Failed to trust CA on Linux: {$result->errorOutput()}",
                TrustStoreInstallReason::CommandFailed,
            );
        }
    }
}
