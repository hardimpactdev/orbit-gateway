<?php

declare(strict_types=1);

namespace App\Tools;

final class PolyscopeServerTool extends BaseTool
{
    public function slug(): string
    {
        return 'polyscope-server';
    }

    #[\Override]
    public function category(): string
    {
        return 'development';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'remove', 'update', 'reconfigure', 'safe-fix', 'safe-adopt'];
    }

    /**
     * @return array{name: string, command: string, runtime: string, tool: string}
     */
    #[\Override]
    public function relatedProcess(): array
    {
        return [
            'name' => 'polyscope-server',
            'command' => 'polyscope-server',
            'runtime' => 'systemd',
            'tool' => 'polyscope',
        ];
    }

    public function installScript(array $config = []): string
    {
        $localTarget = $config['local_target'] ?? false;
        $guidance = $localTarget ? '' : <<<'GUIDE'

echo ""
echo "  Polyscope Server installed but authentication is required."
echo "  Run the following on this node to authenticate:"
echo "    polyscope-server login"
echo ""
GUIDE;

        return <<<"BASH"
#!/usr/bin/env bash
# orbit install polyscope-server
set -e
curl -fsSL https://getpolyscope.com/install/server | bash
{$guidance}
BASH;
    }

    public function removeScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit remove polyscope-server
set -e
home=$(echo $HOME)
rm -f "${home}/.local/bin/polyscope-server"
BASH;
    }

    public function updateScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit update polyscope-server
set -e
home=$(echo $HOME)
"${home}/.local/bin/polyscope-server" update
BASH;
    }

    public function reconfigureScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit reconfigure polyscope-server
set -e
# Runtime changes are owned by the related process. This command only records
# tool capability config in gateway intent.
true
BASH;
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'polyscope-server',
        ];
    }
}
