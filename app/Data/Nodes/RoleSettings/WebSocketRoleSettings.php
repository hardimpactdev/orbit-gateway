<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class WebSocketRoleSettings implements NodeRoleSettings
{
    public function __construct(
        public int $redisNodeId,
    ) {
        if ($redisNodeId < 1) {
            throw new InvalidArgumentException('The websocket role requires a valid redis_node_id setting.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $unknownKeys = array_diff(array_keys($settings), ['redis_node_id']);

        if ($unknownKeys !== []) {
            throw new InvalidArgumentException('The websocket role does not accept unknown settings.');
        }

        $redisNodeId = $settings['redis_node_id'] ?? null;

        if (! is_int($redisNodeId) || $redisNodeId < 1) {
            throw new InvalidArgumentException('The websocket role requires a valid redis_node_id setting.');
        }

        return new self($redisNodeId);
    }

    #[\Override]
    public function toArray(): array
    {
        return ['redis_node_id' => $this->redisNodeId];
    }
}
