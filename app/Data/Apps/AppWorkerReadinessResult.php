<?php

declare(strict_types=1);

namespace App\Data\Apps;

final readonly class AppWorkerReadinessResult
{
    /**
     * @param  list<string>  $missing
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public bool $ready,
        public ?string $code = null,
        public ?string $message = null,
        public array $missing = [],
        public array $meta = [],
    ) {}

    public static function ready(): self
    {
        return new self(ready: true);
    }

    /**
     * @param  list<string>  $missing
     * @param  array<string, mixed>  $meta
     */
    public static function notReady(string $code, string $message, array $missing = [], array $meta = []): self
    {
        return new self(
            ready: false,
            code: $code,
            message: $message,
            missing: $missing,
            meta: $meta,
        );
    }
}
