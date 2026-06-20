<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Profile;

final readonly class ProfileResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
    ) {}
}
