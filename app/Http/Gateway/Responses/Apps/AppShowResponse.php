<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Apps;

final readonly class AppShowResponse
{
    /**
     * @param  array<string, mixed>  $app
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public array $app,
        public array $details,
    ) {}
}
