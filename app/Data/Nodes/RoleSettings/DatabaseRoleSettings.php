<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class DatabaseRoleSettings implements NodeRoleSettings
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        if ($settings !== []) {
            throw new InvalidArgumentException('This role does not accept settings.');
        }

        return new self;
    }

    #[\Override]
    public function toArray(): array
    {
        return [];
    }
}
