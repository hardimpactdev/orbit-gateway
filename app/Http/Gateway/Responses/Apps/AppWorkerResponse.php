<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Apps;

final readonly class AppWorkerResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
    ) {}
}
