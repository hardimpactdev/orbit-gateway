<?php

declare(strict_types=1);

namespace App\Enums\Apps;

enum AppInstanceDriver: string
{
    case Orbit = 'orbit';
    case LaravelCloud = 'laravel-cloud';
}
