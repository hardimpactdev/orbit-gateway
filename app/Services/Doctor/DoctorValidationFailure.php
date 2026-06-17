<?php

declare(strict_types=1);

namespace App\Services\Doctor;

final readonly class DoctorValidationFailure
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
