<?php

declare(strict_types=1);

namespace App\Enums\Apps;

enum NodeRuntimeContainersProbeStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Error = 'error';
}
