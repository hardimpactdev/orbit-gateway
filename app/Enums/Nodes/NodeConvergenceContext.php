<?php

declare(strict_types=1);

namespace App\Enums\Nodes;

enum NodeConvergenceContext: string
{
    case Setup = 'setup';
    case Restore = 'restore';

    public function allowsProvisioningNode(): bool
    {
        return $this === self::Setup;
    }
}
