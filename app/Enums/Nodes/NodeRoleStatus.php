<?php

declare(strict_types=1);

namespace App\Enums\Nodes;

enum NodeRoleStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Error = 'error';
    case Removing = 'removing';
}
