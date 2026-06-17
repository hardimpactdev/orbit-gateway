<?php

declare(strict_types=1);

namespace App\Data\Vpn;

final readonly class VpnBackendClient
{
    public function __construct(
        public string $id,
        public string $name,
        public string $address,
        public bool $enabled,
        public ?string $latestHandshakeAt,
        public ?string $config = null,
    ) {}

    public function withEnabled(bool $enabled): self
    {
        if ($this->enabled === $enabled) {
            return $this;
        }

        return new self(
            id: $this->id,
            name: $this->name,
            address: $this->address,
            enabled: $enabled,
            latestHandshakeAt: $this->latestHandshakeAt,
            config: $this->config,
        );
    }
}
