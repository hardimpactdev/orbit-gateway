<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Doctor;

final readonly class DoctorRunResponse
{
    /**
     * @param  array<string, mixed>  $doctor
     */
    public function __construct(
        public array $doctor,
    ) {}
}
