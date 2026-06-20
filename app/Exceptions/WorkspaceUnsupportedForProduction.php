<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class WorkspaceUnsupportedForProduction extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly array $meta = [],
    ) {
        parent::__construct('Workspaces are only available for app-dev roles.');
    }

    public function errorCode(): string
    {
        return 'workspace.unsupported_for_production';
    }
}
