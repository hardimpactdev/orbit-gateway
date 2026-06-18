<?php

declare(strict_types=1);

namespace App\Data\RemoteShell;

use App\Models\Node;

final readonly class RemoteShellPoolJob
{
    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     env?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    public function __construct(
        public string $key,
        public Node $node,
        public string $script,
        public array $options = [],
    ) {}
}
