<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Data\Nodes\NodeIdentityArtifact;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use JsonException;
use RuntimeException;

final readonly class NodeIdentityArtifactProbe
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    public function read(Node $node): NodeIdentityArtifact
    {
        $interfacePublicKey = $this->readInterfacePublicKey($node);
        $gatewayNode = $this->gatewayRuntimeNode($node);

        $result = $this->remoteShell->run($gatewayNode, $this->registryLookupScript($interfacePublicKey), $this->runtimeOptions($gatewayNode));

        if (! $result->successful()) {
            throw new RuntimeException("Failed to resolve node identity artifact through gateway runtime: {$this->failureOutput($result)}");
        }

        $payload = $this->payload($result);

        return NodeIdentityArtifact::fromArray($payload);
    }

    private function readInterfacePublicKey(Node $node): string
    {
        $result = $this->remoteShell->run($node, $this->interfacePublicKeyScript(), ['timeout' => 15]);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to read node WireGuard interface public key: {$this->failureOutput($result)}");
        }

        return trim($result->stdout);
    }

    private function gatewayRuntimeNode(Node $node): Node
    {
        $roleAssignments = app(NodeRoleAssignments::class);

        if ($roleAssignments->nodeIsGateway($node)) {
            return $node;
        }

        $gatewayNode = $roleAssignments->activeGatewayNodeQuery()->first();

        if (! $gatewayNode instanceof Node) {
            throw new RuntimeException('Failed to resolve node identity artifact through gateway runtime: no active gateway node is registered.');
        }

        return $gatewayNode;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(RemoteShellResult $result): array
    {
        try {
            $payload = json_decode(trim($result->stdout), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to parse node identity artifact JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Node identity artifact response must be a JSON object.');
        }

        return $payload;
    }

    /**
     * @return array{timeout: int, cwd?: string}
     */
    private function runtimeOptions(Node $node): array
    {
        $options = ['timeout' => 15];

        if (is_string($node->orbit_path) && $node->orbit_path !== '') {
            $options['cwd'] = $node->orbit_path;
        }

        return $options;
    }

    private function interfacePublicKeyScript(): string
    {
        return <<<'BASH'
set -e
sudo wg show wg-orbit public-key
BASH;
    }

    private function registryLookupScript(string $interfacePublicKey): string
    {
        $encodedPublicKey = base64_encode($interfacePublicKey);

        return <<<BASH
php apps/gateway/artisan tinker --execute='
\$publicKey = trim((string) base64_decode("{$encodedPublicKey}", true));
\$peer = App\Models\WireGuardPeer::query()
    ->where("public_key", \$publicKey)
    ->first();
\$node = \$peer instanceof App\Models\WireGuardPeer
    ? \$peer->node()->where("status", App\Enums\Nodes\NodeStatus::Active->value)->first()
    : null;
echo json_encode([
    "name" => \$node?->name,
    "role" => \$node?->displayRole(),
    "local_role" => \$node?->displayRole(),
    "status" => \$node?->status?->value,
    "platform" => \$node?->platform,
    "wireguard_address" => \$node?->wireguard_address,
    "registry_public_key" => \$peer?->public_key,
    "interface_public_key" => \$publicKey !== "" ? \$publicKey : null,
], JSON_THROW_ON_ERROR);
'
BASH;
    }

    private function failureOutput(RemoteShellResult $result): string
    {
        return trim($result->output()) ?: 'unknown error';
    }
}
