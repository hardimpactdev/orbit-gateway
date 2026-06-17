<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessEventType: string
{
    case Started = 'started';
    case Stopped = 'stopped';
    case Crashed = 'crashed';
}
