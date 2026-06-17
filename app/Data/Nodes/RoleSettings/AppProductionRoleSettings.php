<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class AppProductionRoleSettings implements NodeRoleSettings
{
    public function __construct(
        public ?int $ingressNodeId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $unknownSettings = array_diff(array_keys($settings), ['ingress_node_id']);

        if ($unknownSettings !== []) {
            throw new InvalidArgumentException('The app-prod role does not accept unknown settings.');
        }

        $ingressNodeId = $settings['ingress_node_id'] ?? null;

        if ($ingressNodeId === null) {
            return new self;
        }

        if (! is_int($ingressNodeId) || $ingressNodeId <= 0) {
            throw new InvalidArgumentException('The app-prod role requires a positive ingress_node_id setting when provided.');
        }

        return new self($ingressNodeId);
    }

    #[\Override]
    public function toArray(): array
    {
        if ($this->ingressNodeId === null) {
            return [];
        }

        return [
            'ingress_node_id' => $this->ingressNodeId,
        ];
    }
}
