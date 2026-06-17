<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityLogType: string
{
    case Read = 'read';
    case Write = 'write';
    case Destructive = 'destructive';
}
