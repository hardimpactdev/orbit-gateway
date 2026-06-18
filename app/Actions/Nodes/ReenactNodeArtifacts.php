<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Contracts\RemoteShell;
use App\Models\Node;

class ReenactNodeArtifacts
{
    /**
     * @param  list<string>  $changed
     * @return list<array<string, string>>
     */
    public function handle(Node $node, array $changed): array
    {
        if (! in_array('gateway_endpoint', $changed, true)) {
            return [];
        }

        if ($node->hasActiveRole('gateway')) {
            return [];
        }

        $endpoint = trim((string) $node->gateway_endpoint);

        if ($endpoint === '') {
            return [$this->warning()];
        }

        $result = app(RemoteShell::class)->run(
            node: $node,
            script: $this->wireGuardEndpointRotationScript("{$endpoint}:51820"),
            options: [
                'timeout' => 30,
                'metadata' => [
                    'operation' => 'node.gateway_endpoint.rotate',
                    'node' => $node->name,
                ],
            ],
        );

        if (! $result->successful()) {
            return [$this->warning()];
        }

        return [];
    }

    private function wireGuardEndpointRotationScript(string $endpoint): string
    {
        $quotedEndpoint = escapeshellarg($endpoint);

        return <<<SH
set -euo pipefail
endpoint={$quotedEndpoint}
timestamp="\$(date -u +%Y%m%d%H%M%S)"
peers_file="\$(mktemp)"
trap 'rm -f "\$peers_file"' EXIT

conf=""
for candidate in /etc/wireguard/wg-orbit.conf /etc/wireguard/wg0.conf; do
    if [ ! -f "\$candidate" ]; then
        continue
    fi

    conf="\$candidate"
    break
done

if [ -z "\$conf" ]; then
    echo "No WireGuard config file found for endpoint rotation." >&2
    exit 1
fi

if ! sudo grep -qE '^Endpoint[[:space:]]*=' "\$conf"; then
    echo "WireGuard config does not contain an Endpoint line: \$conf" >&2
    exit 1
fi

sudo cp -a "\$conf" "\${conf}.before-gateway-endpoint-\${timestamp}"
sudo sed -i -E "s#^Endpoint[[:space:]]*=.*#Endpoint = \${endpoint}#" "\$conf"

iface="\$(basename "\$conf" .conf)"
if sudo wg show "\$iface" peers > "\$peers_file" 2>/dev/null; then
    while IFS= read -r peer; do
        if [ -n "\$peer" ]; then
            sudo wg set "\$iface" peer "\$peer" endpoint "\$endpoint"
        fi
    done < "\$peers_file"
fi
SH;
    }

    /**
     * @return array<string, string>
     */
    private function warning(): array
    {
        return [
            'code' => 'node.artifact_enactment_failed',
            'message' => 'Node artifact re-enactment failed after intent update.',
            'family' => 'node',
            'next_command' => 'doctor --family=node --restore',
        ];
    }
}
