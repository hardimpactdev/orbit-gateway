<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessCrashNotification: string
{
    case None = 'none';
    case AgentIde = 'agent_ide';
}
