<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class AnalyticsRoleSettings implements NodeRoleSettings
{
    public function __construct(
        public int $postgresNodeId,
        public int $clickhouseNodeId,
    ) {
        if ($postgresNodeId < 1 || $clickhouseNodeId < 1) {
            throw new InvalidArgumentException('The analytics role requires valid postgres_node_id and clickhouse_node_id settings.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $unknownKeys = array_diff(array_keys($settings), ['postgres_node_id', 'clickhouse_node_id']);

        if ($unknownKeys !== []) {
            throw new InvalidArgumentException('The analytics role does not accept unknown settings.');
        }

        $postgresNodeId = $settings['postgres_node_id'] ?? null;
        $clickhouseNodeId = $settings['clickhouse_node_id'] ?? null;

        if (! is_int($postgresNodeId) || ! is_int($clickhouseNodeId)) {
            throw new InvalidArgumentException('The analytics role requires valid postgres_node_id and clickhouse_node_id settings.');
        }

        return new self(
            postgresNodeId: $postgresNodeId,
            clickhouseNodeId: $clickhouseNodeId,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'postgres_node_id' => $this->postgresNodeId,
            'clickhouse_node_id' => $this->clickhouseNodeId,
        ];
    }
}
