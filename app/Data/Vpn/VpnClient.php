<?php

declare(strict_types=1);

namespace App\Data\Vpn;

final readonly class VpnClient
{
    public function __construct(
        public string $id,
        public string $name,
        public string $address,
        public bool $enabled,
        public ?string $latestHandshakeAt,
        public string $kind,
        public ?string $config = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeConfig = true): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'enabled' => $this->enabled,
            'latest_handshake_at' => $this->latestHandshakeAt,
            'kind' => $this->kind,
        ];

        if ($includeConfig && $this->config !== null) {
            $data['config'] = $this->config;
        }

        return $data;
    }
}
