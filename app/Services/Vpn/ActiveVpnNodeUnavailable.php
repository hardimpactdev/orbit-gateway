<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use RuntimeException;

final class ActiveVpnNodeUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No active vpn role node is available.');
    }
}
