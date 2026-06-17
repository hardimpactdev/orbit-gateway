<?php

declare(strict_types=1);

namespace App\Data\Php;

final readonly class PhpRuntimeFailure
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $meta = [],
    ) {}
}
