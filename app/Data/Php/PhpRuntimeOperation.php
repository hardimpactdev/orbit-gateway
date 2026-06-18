<?php

declare(strict_types=1);

namespace App\Data\Php;

final readonly class PhpRuntimeOperation
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $payload = [],
        public array $meta = [],
        public ?PhpRuntimeFailure $failure = null,
    ) {}

    public function failed(): bool
    {
        return $this->failure instanceof PhpRuntimeFailure;
    }
}
