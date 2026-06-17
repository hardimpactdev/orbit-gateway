<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Models\Node;
use InvalidArgumentException;
use RuntimeException;

class WebSocketBackendName
{
    public function forNode(Node $node): string
    {
        $wireGuardAddress = trim((string) $node->wireguard_address);

        if ($wireGuardAddress === '') {
            throw new RuntimeException('The websocket backend requires a WireGuard address.');
        }

        if (filter_var($wireGuardAddress, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('The websocket backend identity requires a valid WireGuard IP address.');
        }

        return $wireGuardAddress;
    }
}
