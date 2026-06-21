<?php

declare(strict_types=1);

namespace App\Data\Nodes;

final readonly class NodeIdentityArtifact
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            name: self::nullableString($payload['name'] ?? null),
            role: self::nullableString($payload['role'] ?? null),
            localRole: self::nullableString($payload['local_role'] ?? null),
            status: self::nullableString($payload['status'] ?? null),
            platform: self::nullableString($payload['platform'] ?? null),
            wireguardAddress: self::nullableString($payload['wireguard_address'] ?? null),
            registryPublicKey: self::nullableString($payload['registry_public_key'] ?? null),
            interfacePublicKey: self::nullableString($payload['interface_public_key'] ?? null),
        );
    }

    public function __construct(
        public ?string $name,
        public ?string $role,
        public ?string $localRole,
        public ?string $status,
        public ?string $platform,
        public ?string $wireguardAddress,
        public ?string $registryPublicKey,
        public ?string $interfacePublicKey,
    ) {}

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
