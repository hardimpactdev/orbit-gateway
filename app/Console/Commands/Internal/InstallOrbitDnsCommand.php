<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Services\Dns\OrbitDnsServiceInstaller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('orbit:internal:install-orbit-dns')]
#[Description('Install or refresh the gateway orbit-dns container and dnsmasq.conf')]
class InstallOrbitDnsCommand extends Command
{
    public function handle(OrbitDnsServiceInstaller $installer): int
    {
        $installer->install();

        return self::SUCCESS;
    }
}
