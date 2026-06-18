<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Contracts\RemoteShell;
use App\Models\Node;
use RuntimeException;

final readonly class RemoteSecretFile
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @template TResult
     *
     * @param  callable(string): TResult  $callback
     * @return TResult
     */
    public function stage(Node $node, string $secret, callable $callback): mixed
    {
        $staged = $this->remoteShell->run($node, <<<'SH'
set -euo pipefail
path="$(mktemp /tmp/orbit-secret.XXXXXX)"
base64 -d > "$path"
chmod 600 "$path"
printf '%s\n' "$path"
SH, [
            'input' => base64_encode($secret),
            'throw' => false,
        ]);

        if (! $staged->successful()) {
            throw new RuntimeException(trim($staged->stderr) ?: 'Remote secret file staging failed.');
        }

        $path = trim($staged->stdout);

        if ($path === '') {
            throw new RuntimeException('Remote secret file staging did not return a path.');
        }

        try {
            return $callback($path);
        } finally {
            $this->remoteShell->run($node, 'rm -f '.escapeshellarg($path), ['throw' => false]);
        }
    }
}
