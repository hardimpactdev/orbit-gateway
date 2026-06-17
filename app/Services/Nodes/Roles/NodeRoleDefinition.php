<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Data\Nodes\RoleSettings\NodeRoleSettings;

final readonly class NodeRoleDefinition
{
    /**
     * @param  list<string>  $conflictsWith
     * @param  list<string>  $supportedPlatforms
     * @param  class-string<NodeRoleSettings>  $settingsClass
     */
    public function __construct(
        public string $name,
        public array $conflictsWith,
        public array $supportedPlatforms,
        public string $settingsClass,
        public bool $assignableByRoleCommand = true,
        public bool $assignableByNodeNew = true,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public function settingsFromArray(array $settings): NodeRoleSettings
    {
        return $this->settingsClass::fromArray($settings);
    }
}
