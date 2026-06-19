<?php

declare(strict_types=1);

namespace App\Tools;

final class GhTool extends BaseTool
{
    public function slug(): string
    {
        return 'gh';
    }

    #[\Override]
    public function category(): string
    {
        return 'runtime';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'update', 'safe-adopt'];
    }

    #[\Override]
    public function installScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit install gh
set -e

export DEBIAN_FRONTEND=noninteractive

if ! command -v wget >/dev/null 2>&1; then
    sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
    sudo apt-get -o DPkg::Lock::Timeout=300 install -y -qq wget
fi

sudo install -d -m 0755 /etc/apt/keyrings
keyring="$(mktemp)"
wget -nv -O"${keyring}" https://cli.github.com/packages/githubcli-archive-keyring.gpg
cat "${keyring}" | sudo tee /etc/apt/keyrings/githubcli-archive-keyring.gpg >/dev/null
rm -f "${keyring}"
sudo chmod go+r /etc/apt/keyrings/githubcli-archive-keyring.gpg

sudo install -d -m 0755 /etc/apt/sources.list.d
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | sudo tee /etc/apt/sources.list.d/github-cli.list >/dev/null

sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
sudo apt-get -o DPkg::Lock::Timeout=300 install -y -qq gh
BASH;
    }

    public function updateScript(array $config = []): string
    {
        return 'export DEBIAN_FRONTEND=noninteractive && sudo apt-get -o DPkg::Lock::Timeout=300 update -qq && sudo apt-get -o DPkg::Lock::Timeout=300 install --only-upgrade -y -qq gh';
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'gh',
            'version_command' => 'gh --version',
            'update_command' => $this->updateScript(),
        ];
    }
}
