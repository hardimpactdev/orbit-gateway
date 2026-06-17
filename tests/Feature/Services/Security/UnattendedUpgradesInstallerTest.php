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
