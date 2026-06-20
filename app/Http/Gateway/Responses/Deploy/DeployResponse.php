<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Deploy;

final readonly class DeployResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $data,
        public array $meta = [],
    ) {}
}
