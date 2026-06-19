<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\App;
use HardImpact\OpenCode\OpenCode;

interface OpenCodeClientFactory
{
    public function forApp(App $app): OpenCode;
}
