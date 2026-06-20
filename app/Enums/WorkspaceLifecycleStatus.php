<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkspaceLifecycleStatus: string
{
    case Expected = 'expected';
    case SetupPending = 'setup-pending';
    case SettingUp = 'setting_up';
    case Active = 'active';
}
