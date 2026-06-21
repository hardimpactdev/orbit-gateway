<?php

declare(strict_types=1);

namespace App\Services\Nodes\Access;

final readonly class AuthorizationResult
{
    public function __construct(
        public bool $allowed,
        public ?string $missingPermission = null,
        public ?string $reason = null,
    ) {}

    public static function allow(string $reason): self
    {
        return new self(
            allowed: true,
            reason: $reason,
        );
    }

    public static function deny(string $permission, string $reason = 'missing_permission'): self
    {
        return new self(
            allowed: false,
            missingPermission: $permission,
            reason: $reason,
        );
    }
}
