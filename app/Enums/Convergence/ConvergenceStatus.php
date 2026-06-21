<?php

declare(strict_types=1);

namespace App\Enums\Convergence;

enum ConvergenceStatus: string
{
    case Ok = 'ok';
    case Changed = 'changed';
    case Failed = 'failed';
    case Unreachable = 'unreachable';
}
