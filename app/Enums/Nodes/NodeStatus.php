<?php

declare(strict_types=1);

namespace App\Enums\Nodes;

enum NodeStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Inactive = 'inactive';
    case Decommissioned = 'decommissioned';
    case Removing = 'removing';
}
