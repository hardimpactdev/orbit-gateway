<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Data\Nodes\NodeIdentityArtifact;
use App\Models\Node;
use JsonException;
use RuntimeException;

final readonly class NodeIdentityArtifactProbe
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    public function read(Node $node): NodeIdentityArtifact
    {
        $result = $this->remoteShell->run($node, $this->script(), $this->options($node));

        if (! $result->successful()) {
            throw new RuntimeException('Failed to read node identity artifact: '.(trim($result->output()) ?: 'unknown error'));
        }

        try {
            $payload = json_decode(trim($result->stdout), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to parse node identity artifact JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Node identity artifact response must be a JSON object.');
        }

        return NodeIdentityArtifact::fromArray($payload);
    }

    /**
     * @return array{timeout: int, cwd?: string}
     */
    private function options(Node $node): array
    {
        $options = ['timeout' => 15];

        if (is_string($node->orbit_path) && $node->orbit_path !== '') {
            $options['cwd'] = $node->orbit_path;
        }

        return $options;
    }

    private function script(): string
    {
        return <<<'BASH'
set -e
wireguard_public_key="$(sudo wg show wg-orbit public-key 2>/dev/null || true)"
export ORBIT_WIREGUARD_PUBLIC_KEY="$wireguard_public_key"
php -r '
$base = getcwd();
require $base."/vendor/autoload.php";
$app = require $base."/apps/gateway/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$peer = App\Models\WireGuardPeer::query()
    ->where("public_key", trim((string) getenv("ORBIT_WIREGUARD_PUBLIC_KEY")))
    ->first();
$node = $peer instanceof App\Models\WireGuardPeer
    ? $peer->node()->where("status", App\Enums\Nodes\NodeStatus::Active->value)->first()
    : null;
echo json_encode([
    "name" => $node?->name,
    "role" => $node?->displayRole(),
    "local_role" => $node?->displayRole(),
    "status" => $node?->status?->value,
    "platform" => $node?->platform,
    "wireguard_address" => $node?->wireguard_address,
    "registry_public_key" => $peer?->public_key,
    "interface_public_key" => trim((string) getenv("ORBIT_WIREGUARD_PUBLIC_KEY")) ?: null,
], JSON_THROW_ON_ERROR);
'
BASH;
    }
}
