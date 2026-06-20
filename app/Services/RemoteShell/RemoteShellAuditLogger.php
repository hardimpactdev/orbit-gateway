<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use Illuminate\Support\Facades\Schema;

final readonly class RemoteShellAuditLogger
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function log(string $event, Node $node, string $script, array $options, ?RemoteShellResult $result = null): void
    {
        if (! Schema::hasTable('activity_log')) {
            return;
        }

        $metadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : [];
        $input = array_key_exists('input', $options) ? (string) $options['input'] : null;

        activity('remote_shell')
            ->event($event)
            ->performedOn($node)
            ->withProperties(array_filter([
                'type' => 'remote_execution',
                'node' => $node->name,
                'script_sha256' => hash('sha256', $script),
                'input_sha256' => $input === null ? null : hash('sha256', $input),
                'metadata_keys' => array_values(array_map(strval(...), array_keys($metadata))),
                'cwd_set' => isset($options['cwd']) && $options['cwd'] !== '',
                'strict' => (bool) ($options['strict'] ?? false),
                'timeout' => isset($options['timeout']) ? (int) $options['timeout'] : null,
                'exit_code' => $result?->exitCode,
                'duration_ms' => $result?->durationMs,
                'status' => $result === null ? 'started' : ($result->successful() ? 'succeeded' : 'failed'),
            ], fn (mixed $value): bool => $value !== null))
            ->log($event);
    }
}
