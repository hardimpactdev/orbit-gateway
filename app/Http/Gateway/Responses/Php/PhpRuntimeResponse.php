<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Php;

final readonly class PhpRuntimeResponse
{
    /**
     * @param  array<string, mixed>  $php
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $php,
        public array $meta = [],
    ) {}
}
