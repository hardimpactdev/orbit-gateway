<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;

/**
 * Default `RemoteShell` binding for tests. Returns an empty success result
 * so production code that resolves `RemoteShell::class` without a test-supplied
 * fake never falls through to the real `SshRemoteShell` and never blocks on a
 * 30s connect timeout.
 *
 * Tests that need behavior beyond "succeed silently" should bind their own
 * fake via `app()->instance(RemoteShell::class, ...)` — that override wins.
 */
final readonly class NullRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 0);
    }
}
