<?php

declare(strict_types=1);

namespace App\Enums\Processes;

enum ProcessDockerContainerApplyOutcome: string
{
    case Created = 'created';
    case Recreated = 'recreated';
    case Started = 'started';
    case Unchanged = 'unchanged';
}
