<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Node;
use App\Models\WireGuardPeer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WireGuardPeer>
 */
class WireGuardPeerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'public_key' => base64_encode(random_bytes(32)),
            'private_key' => base64_encode(random_bytes(32)),
            'pre_shared_key' => base64_encode(random_bytes(32)),
            'allowed_ips' => '10.0.0.2/32, fd00::2/128',
        ];
    }
}
