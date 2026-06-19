<?php

declare(strict_types=1);

use App\Services\Security\UnattendedUpgradesInstaller;
use Orbit\Core\Updates\UnattendedUpgradesAptConfig;

it('renders the shared unattended-upgrades apt configuration', function (): void {
    $config = new UnattendedUpgradesAptConfig;
    $script = app(UnattendedUpgradesInstaller::class)->script();

    expect($script)
        ->toContain($config->autoUpgrades())
        ->toContain($config->unattendedUpgrades());
});

it('installs the unattended-upgrades package only when it is absent', function (): void {
    $script = app(UnattendedUpgradesInstaller::class)->script();

    expect($script)
        ->toContain('command -v unattended-upgrade >/dev/null 2>&1')
        ->toContain('dpkg-query -W -f=\'${Status}\' unattended-upgrades')
        ->toContain("grep -q 'install ok installed'")
        ->toContain('if !')
        ->toContain('apt-get -o DPkg::Lock::Timeout=300 update -qq')
        ->toContain('install -y -qq unattended-upgrades');
});
