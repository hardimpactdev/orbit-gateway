<?php

declare(strict_types=1);

namespace App\Enums\Trust;

enum TrustStoreInstallReason: string
{
    case UnsupportedPlatform = 'unsupported_platform';
    case CommandFailed = 'command_failed';
    case RootCaUnreadable = 'root_ca_unreadable';
    case AlreadyTrusted = 'already_trusted';
}
