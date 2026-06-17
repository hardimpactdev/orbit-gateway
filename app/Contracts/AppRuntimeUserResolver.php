<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\App;

interface AppRuntimeUserResolver
{
    public function forApp(App $app): string;
}
