<?php

declare(strict_types=1);

namespace App\Tools;

final class OpenClawTool extends BaseTool
{
    public function slug(): string
    {
        return 'openclaw';
    }

    #[\Override]
    public function requiredNodeRole(): string
    {
        return 'agent';
    }

    #[\Override]
    public function category(): string
    {
        return 'agent';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'remove', 'update', 'reconfigure', 'credentials', 'safe-fix', 'safe-adopt'];
    }

    public function installScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit install openclaw
set -e
sudo -u agent -H bash -lc 'curl -fsSL https://openclaw.ai/install.sh | bash -s -- --no-onboard'
BASH;
    }

    public function removeScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit remove openclaw
set -e
sudo -u agent -H bash -lc 'npm uninstall -g openclaw 2>/dev/null || true'
sudo -u agent -H bash -lc 'rm -rf "${HOME}/.openclaw" 2>/dev/null || true'
BASH;
    }

    public function updateScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit update openclaw
set -e
sudo -u agent -H bash -lc 'npm install -g openclaw@latest'
BASH;
    }

    public function credentialsScript(array $config = []): string
    {
        $hostname = $config['hostname'] ?? 'openclaw.agent';

        return <<<"BASH"
cat <<EOF
{
  "url": "https://{$hostname}",
  "username": "orbit",
  "password": "<generated-password>"
}
EOF
BASH;
    }

    public function reconfigureScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit reconfigure openclaw
set -e
sudo -u agent -H bash -lc 'openclaw reconfigure 2>/dev/null || true'
BASH;
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'openclaw',
            'version_command' => 'sudo -u agent -H bash -lc "openclaw --version"',
            'service' => 'openclaw',
            'update_command' => $this->updateScript(),
            'repair_commands' => [
                'lifecycle_running' => 'sudo -u agent -H bash -lc "openclaw start 2>/dev/null || true"',
                'lifecycle_stopped' => 'sudo -u agent -H bash -lc "openclaw stop 2>/dev/null || true"',
                'lifecycle_restarted' => 'sudo -u agent -H bash -lc "openclaw restart 2>/dev/null || true"',
            ],
        ];
    }
}
