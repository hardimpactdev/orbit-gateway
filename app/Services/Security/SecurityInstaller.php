<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\RemoteShell;
use App\Models\Node;

interface SecurityInstaller
{
    public function installFor(Node $node, RemoteShell $shell): InstallReport;
}
