<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Contracts\RemoteShellStream;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Support\Facades\Process;

final readonly class SshRemoteShellStream implements RemoteShellStream
{
    /**
     * @param  callable(string): void  $onOutput
     * @param  array{
     *      cwd?: string,
     *      timeout?: int|null,
     *      metadata?: array<string, string>,
     *  }  $options
     */
    public function stream(Node $node, string $script, callable $onOutput, array $options = []): int
    {
        $pendingProcess = array_key_exists('timeout', $options) && $options['timeout'] !== null
            ? Process::timeout((int) $options['timeout'])
            : Process::forever();

        $startedAt = hrtime(true);
        $result = $pendingProcess->run(
            $this->command($node, $this->composeScript($script, $options)),
            function (string $type, string $output) use ($onOutput): void {
                if ($type === 'out') {
                    $onOutput($output);
                }
            },
        );
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $shellResult = new RemoteShellResult(
            exitCode: $result->exitCode() ?? 1,
            stdout: $result->output(),
            stderr: $result->errorOutput(),
            durationMs: $durationMs,
        );

        app(RemoteShellAuditLogger::class)->log('remote_shell.stream', $node, $script, $options, $shellResult);

        return $result->exitCode() ?? 1;
    }

    /**
     * @param  array{cwd?: string, metadata?: array<string, string>}  $options
     */
    private function composeScript(string $script, array $options): string
    {
        $prefix = '';

        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $metadata = [];

            foreach ($options['metadata'] as $key => $value) {
                $metadata[(string) $key] = (string) $value;
            }

            $prefix .= app(RemoteShellMetadata::class)->prologue($metadata);
        }

        if (isset($options['cwd']) && $options['cwd'] !== '') {
            $prefix .= 'cd '.escapeshellarg($options['cwd']).' && ';
        }

        return $prefix.$script;
    }

    private function command(Node $node, string $script): string
    {
        if (app(NodeRoleAssignments::class)->nodeIsGateway($node)) {
            return 'bash -c '.escapeshellarg($script);
        }

        return app(SshCommandBuilder::class)->enforceForNode(
            node: $node,
            remoteCommand: 'bash -lc '.escapeshellarg($script),
            options: [
                'server_alive_interval' => 30,
                'server_alive_count_max' => 10,
            ],
        );
    }
}
