<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class ProviderSelection
{
    public function __construct(
        public ?E2EProvider $provider,
        public string $message,
    ) {}

    public function available(): bool
    {
        return $this->provider !== null;
    }

    public function provider(): E2EProvider
    {
        if ($this->provider === null) {
            throw new \RuntimeException($this->message);
        }

        return $this->provider;
    }
}
