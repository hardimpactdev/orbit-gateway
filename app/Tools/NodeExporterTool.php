<?php

declare(strict_types=1);

namespace App\Tools;

final class NodeExporterTool extends BaseTool
{
    public const string Version = '1.11.1';

    public function slug(): string
    {
        return 'node-exporter';
    }

    #[\Override]
    public function category(): string
    {
        return 'observability';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'remove', 'update', 'safe-fix', 'safe-adopt'];
    }

    #[\Override]
    public function installScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit install node-exporter
set -euo pipefail

case "$(uname -s)" in
    Linux) ;;
    *) echo "node-exporter: unsupported os" >&2; exit 64 ;;
esac

case "$(uname -m)" in
    x86_64|amd64) node_exporter_arch=amd64 ;;
    aarch64|arm64) node_exporter_arch=arm64 ;;
    *) echo "node-exporter: unsupported arch $(uname -m)" >&2; exit 64 ;;
esac

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

node_exporter_url="https://github.com/prometheus/node_exporter/releases/download/v1.11.1/node_exporter-1.11.1.linux-${node_exporter_arch}.tar.gz"
curl -fsSL "$node_exporter_url" -o "$tmp_dir/node_exporter.tar.gz"
tar -xzf "$tmp_dir/node_exporter.tar.gz" -C "$tmp_dir"

sudo install -m 0755 "$tmp_dir/node_exporter-1.11.1.linux-${node_exporter_arch}/node_exporter" /usr/local/bin/node_exporter
/usr/local/bin/node_exporter --version >/dev/null
BASH;
    }

    #[\Override]
    public function removeScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit remove node-exporter
set -e
sudo rm -f /usr/local/bin/node_exporter
BASH;
    }

    #[\Override]
    public function updateScript(array $config = []): string
    {
        return $this->installScript($config);
    }

    #[\Override]
    public function latestSupportedVersion(): string
    {
        return self::Version;
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => '/usr/local/bin/node_exporter',
            'version_command' => '/usr/local/bin/node_exporter --version 2>/dev/null | head -n 1',
        ];
    }
}
