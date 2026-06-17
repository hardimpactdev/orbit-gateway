<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;
use RuntimeException;

final class NodeWireGuardServiceAddress
{
    public function forServiceOn(Node $ownerNode, Node $consumerNode, string $serviceType = 'service'): string
    {
        $address = trim((string) $ownerNode->wireguard_address);

        if ($address === '') {
            throw new RuntimeException("Node {$ownerNode->name} cannot provide {$serviceType} service address because it has no WireGuard address.");
        }

        return $address;
    }
}
