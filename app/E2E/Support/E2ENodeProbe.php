<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2ENodeProbe
{
    public static function assertOrbitInstalled(E2EInstance $instance): void
    {
        $install = $instance->exec('test -d /home/orbit/orbit && test -f /home/orbit/orbit/apps/gateway/artisan');
        $version = $instance->exec("sudo -iu orbit bash -lc 'orbit --version --local >/dev/null'");

        if (! $install->successful()) {
            throw new \RuntimeException(trim($install->output().$install->errorOutput()) ?: 'Orbit install files were not found.');
        }

        if (! $version->successful()) {
            throw new \RuntimeException(trim($version->output().$version->errorOutput()) ?: 'Orbit version command failed.');
        }
    }
}
