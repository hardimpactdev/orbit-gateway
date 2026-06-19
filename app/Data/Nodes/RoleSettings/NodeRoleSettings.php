<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

interface NodeRoleSettings
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
