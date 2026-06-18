<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use RuntimeException;

final class RemoteShellFailed extends RuntimeException
{
    public function __construct(
        public readonly Node $node,
        public readonly string $script,
        public readonly RemoteShellResult $result,
    ) {
        $output = trim($result->output());

        parent::__construct(sprintf(
            'RemoteShell failed on %s (exit %d): %s',
            $node->name,
            $result->exitCode,
            $output !== '' ? $output : '(no output)',
        ));
    }
}
