<?php

declare(strict_types=1);

namespace App\Enums;

enum DriftKind: string
{
    case Missing = 'missing';
    case Extra = 'extra';
    case Divergent = 'divergent';
    case Unverifiable = 'unverifiable';
    case Unknown = 'unknown';
}
