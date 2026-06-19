<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

final class ActivityLogCorrelation
{
    private ?string $uuid = null;

    public function start(?string $uuid = null): string
    {
        if ($this->uuid !== null) {
            return $this->uuid;
        }

        $this->uuid = $uuid ?? (string) Str::uuid();

        return $this->uuid;
    }

    public function current(): ?string
    {
        return $this->uuid;
    }

    public function end(): void
    {
        $this->uuid = null;
    }
}
