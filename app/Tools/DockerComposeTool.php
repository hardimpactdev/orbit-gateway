<?php

declare(strict_types=1);

namespace App\Tools;

abstract class DockerComposeTool extends BaseTool
{
    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'remove', 'update', 'credentials', 'safe-fix', 'safe-adopt'];
    }

    public function installScript(array $config = []): string
    {
        return $this->dockerComposeInstallScript($this->service(), $config);
    }

    protected function installWithAptPackages(array $config, string ...$packages): string
    {
        return implode("\n", [
            'set -e',
            $this->aptInstallScript(...$packages),
            $this->dockerComposeInstallScript($this->service(), $config),
        ]);
    }

    public function removeScript(array $config = []): string
    {
        return $this->dockerComposeRemoveScript($this->service(), $config);
    }

    public function updateScript(array $config = []): string
    {
        return $this->installScript($config);
    }

    protected function service(): string
    {
        return $this->slug();
    }
}
