<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Node;
use Illuminate\Contracts\Process\InvokedProcess;

interface StartsRemoteShellProcesses
{
    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    public function start(Node $node, string $script, array $options = []): InvokedProcess;
}
