<?php

declare(strict_types=1);

namespace App\Enums;

enum AdoptAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Skipped = 'skipped';
    case Conflict = 'conflict';
}
