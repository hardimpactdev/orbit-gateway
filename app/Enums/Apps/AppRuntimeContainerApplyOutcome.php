<?php

declare(strict_types=1);

namespace App\Enums\Apps;

enum AppRuntimeContainerApplyOutcome: string
{
    case Created = 'created';
    case Recreated = 'recreated';
    case Started = 'started';
    case Unchanged = 'unchanged';
}
