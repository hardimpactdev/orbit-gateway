<?php

declare(strict_types=1);

namespace App\Tools;

final class OpenCodeServerTool extends BaseTool
{
    public function slug(): string
    {
        return 'opencode-server';
    }

    #[\Override]
    public function category(): string
    {
        return 'development';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'remove', 'update', 'reconfigure', 'credentials', 'safe-fix'];
    }

    /**
     * @return array{name: string, command: string, runtime: string, tool: string}
     */
    #[\Override]
    public function relatedProcess(): array
    {
        return [
            'name' => 'opencode-server',
            'command' => 'opencode serve -a',
            'runtime' => 'systemd',
            'tool' => 'opencode',
        ];
    }

    public function installScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit install opencode-server
set -e
curl -fsSL https://opencode.ai/install | bash
BASH;
    }

    public function removeScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit remove opencode-server
set -e
home=$(echo $HOME)
rm -rf "${home}/.opencode"
BASH;
    }

    public function updateScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit update opencode-server
set -e
home=$(echo $HOME)
"${home}/.opencode/bin/opencode" upgrade
BASH;
    }

    public function credentialsScript(array $config = []): string
    {
        $hostname = $config['hostname'] ?? '0.0.0.0';
        $port = (int) ($config['port'] ?? 4096);
        $username = $config['username'] ?? 'opencode';
        $password = $config['password'] ?? null;

        $authUsername = $password === null || $password === '' ? '(no auth)' : $username;
        $authPassword = $password === null || $password === '' ? '(no auth)' : $password;

        return <<<"BASH"
cat <<EOF
{
  "Host": "{$hostname}",
  "Port": "{$port}",
  "Username": "{$authUsername}",
  "Password": "{$authPassword}"
}
EOF
BASH;
    }

    public function reconfigureScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit reconfigure opencode-server
set -e
# Runtime changes are owned by the related process. This command only records
# tool capability config and credentials in gateway intent.
true
BASH;
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'opencode',
        ];
    }
}
