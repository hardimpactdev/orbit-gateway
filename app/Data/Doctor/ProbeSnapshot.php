<?php

declare(strict_types=1);

namespace App\Data\Doctor;

final readonly class ProbeSnapshot
{
    /**
     * @param  array<string, array<string, mixed>>  $items
     */
    public function __construct(public array $items) {}

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->items);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        return $this->items[$key] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
