<?php

declare(strict_types=1);

namespace App\Data\Doctor;

use App\Enums\AdoptAction;

final readonly class AdoptResult
{
    /**
     * @param  array<string, mixed>|null  $detail
     */
    public function __construct(
        public string $family,
        public string $key,
        public AdoptAction $action,
        public string $summary,
        public ?array $detail = null,
    ) {}

    /**
     * @return array{family: string, key: string, action: string, summary: string, detail: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'family' => $this->family,
            'key' => $this->key,
            'action' => $this->action->value,
            'summary' => $this->summary,
            'detail' => $this->detail,
        ];
    }
}
