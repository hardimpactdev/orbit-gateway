<?php

declare(strict_types=1);

use App\Services\Security\InstallReport;
use App\Services\Security\SecurityInstaller;

it('defines the shared security installer contract without registering a doctor family', function (): void {
    expect(interface_exists(SecurityInstaller::class))->toBeTrue()
        ->and(class_exists(InstallReport::class))->toBeTrue();
});
