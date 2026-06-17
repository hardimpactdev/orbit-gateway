<?php

declare(strict_types=1);

namespace App\Tools;

final class HermesTool extends BaseTool
{
    public function slug(): string
    {
        return 'hermes';
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
# orbit install hermes
set -e
sudo -u agent -H bash -lc 'curl -fsSL https://raw.githubusercontent.com/NousResearch/hermes-agent/main/scripts/install.sh | bash -s -- --skip-setup'
sudo tee /usr/local/bin/hermes >/dev/null <<'SH'
#!/usr/bin/env bash
exec sudo -u agent -H /home/agent/.local/bin/hermes "$@"
SH
sudo chmod 0755 /usr/local/bin/hermes
BASH;
    }

    public function removeScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit remove hermes
set -e
sudo -u agent -H bash -lc 'rm -rf "${HOME}/.hermes" 2>/dev/null || true'
sudo -u agent -H bash -lc 'rm -f "${HOME}/.local/bin/hermes" 2>/dev/null || true'
sudo rm -f /usr/local/bin/hermes
BASH;
    }

    public function updateScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit update hermes
set -e
sudo -u agent -H bash -lc 'hermes update'
BASH;
    }

    public function credentialsScript(array $config = []): string
    {
        $hostname = $config['hostname'] ?? 'hermes.agent';

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
# orbit reconfigure hermes
set -e
sudo -u agent -H bash -lc 'hermes setup 2>/dev/null || true'
BASH;
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => '/usr/local/bin/hermes',
            'version_command' => '/usr/local/bin/hermes --version 2>/dev/null || true',
            'service' => 'hermes',
            'update_command' => $this->updateScript(),
            'repair_commands' => [
                'lifecycle_running' => 'sudo -u agent -H bash -lc "hermes start 2>/dev/null || true"',
                'lifecycle_stopped' => 'sudo -u agent -H bash -lc "hermes stop 2>/dev/null || true"',
                'lifecycle_restarted' => 'sudo -u agent -H bash -lc "hermes restart 2>/dev/null || true"',
            ],
        ];
    }
}
