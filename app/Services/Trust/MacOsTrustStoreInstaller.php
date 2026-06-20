<?php

declare(strict_types=1);

namespace App\Services\Trust;

use App\Enums\Trust\TrustStoreInstallReason;
use Closure;
use Illuminate\Support\Facades\Process;

final class MacOsTrustStoreInstaller implements TrustStoreInstaller
{
    private const int COMMAND_TIMEOUT = 30;

    public function isCaTrusted(string $rootCaPath, string $label): bool
    {
        return Process::timeout(self::COMMAND_TIMEOUT)
            ->run(sprintf(
                'security find-certificate -c %s /Library/Keychains/System.keychain 2>/dev/null',
                escapeshellarg($label),
            ))
            ->successful();
    }

    public function trustCa(string $rootCaPath, string $label, ?Closure $log = null): void
    {
        $command = sprintf(
            'sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain %s',
            escapeshellarg($rootCaPath),
        );

        if ($log !== null) {
            $log("Running: {$command}");
        }

        $result = Process::timeout(self::COMMAND_TIMEOUT)->run($command);

        if (! $result->successful()) {
            throw new TrustStoreInstallException(
                "Failed to trust CA on macOS: {$result->errorOutput()}",
                TrustStoreInstallReason::CommandFailed,
            );
        }
    }
}
