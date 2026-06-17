<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Contracts\RemoteShell;
use App\Contracts\StartsRemoteShellProcesses;

interface RemoteExecutor extends RemoteShell, StartsRemoteShellProcesses {}
