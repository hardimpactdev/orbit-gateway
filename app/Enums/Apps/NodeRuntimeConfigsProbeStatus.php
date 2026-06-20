<?php

declare(strict_types=1);

namespace App\Enums\Apps;

enum NodeRuntimeConfigsProbeStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Error = 'error';
}
