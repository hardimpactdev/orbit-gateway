<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\RemoteShell\SshRemoteShell;
use Illuminate\Contracts\Process\ProcessResult as ProcessResultContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function nodeWithPinnedHostKeyForMetadata(): Node
{
    return Node::factory()->create([
        'host' => 'app.example.com',
        'wireguard_address' => '10.6.0.50',
        'user' => 'orbit',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIMetadataPinnedKey',
        'host_key_fingerprint' => 'SHA256:metadata',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ]);
}

it('rejects metadata keys outside the closed whitelist', function (): void {
    Process::fake();
    Process::preventStrayProcesses();

    expect(fn () => (new SshRemoteShell)->run(nodeWithPinnedHostKeyForMetadata(), 'echo ok', [
        'metadata' => ['APP_ENV' => 'production'],
    ]))->toThrow(InvalidArgumentException::class, 'Remote shell metadata key');

    Process::assertRanTimes(fn (): bool => true, 0);
});

it('rejects invalid metadata values at the call site', function (string $value): void {
    Process::fake();
    Process::preventStrayProcesses();

    expect(fn () => (new SshRemoteShell)->run(nodeWithPinnedHostKeyForMetadata(), 'echo ok', [
        'metadata' => ['ORBIT_REQUEST_ID' => $value],
    ]))->toThrow(InvalidArgumentException::class);

    Process::assertRanTimes(fn (): bool => true, 0);
})->with([
    "line\nbreak",
    "line\rbreak",
    "contains\0nul",
    str_repeat('a', 4097),
]);

it('keeps metadata in a shell-escaped prologue and preserves the user command body', function (): void {
    Process::fake(['*' => Process::result(output: "ok\n")]);
    Process::preventStrayProcesses();

    $node = nodeWithPinnedHostKeyForMetadata();
    $body = 'printf "%s" "$ORBIT_REQUEST_ID"; test ! -f /tmp/orbit-metadata-pwned';
    $metadata = '; | & $ ( ) < > '."'".' " ` space';

    (new SshRemoteShell)->run($node, $body, [
        'metadata' => ['ORBIT_REQUEST_ID' => $metadata],
    ]);

    Process::assertRan(function (PendingProcess $process, ProcessResultContract $result): bool {
        $command = (string) $process->command;

        return str_contains($command, 'export ORBIT_REQUEST_ID=')
            && str_contains($command, 'printf "%s" "$ORBIT_REQUEST_ID"')
            && str_contains($command, 'test ! -f /tmp/orbit-metadata-pwned')
            && $result->output() === "ok\n";
    });
});

it('allows local executor operation id metadata', function (): void {
    Process::fake(['*' => Process::result(output: "ok\n")]);
    Process::preventStrayProcesses();

    (new SshRemoteShell)->run(nodeWithPinnedHostKeyForMetadata(), 'echo "$ORBIT_OPERATION_ID"', [
        'metadata' => ['ORBIT_OPERATION_ID' => '00000000-0000-4000-8000-000000000402'],
    ]);

    Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->command, 'export ORBIT_OPERATION_ID='));
});

it('allows proxy route suffix metadata used by doctor probes', function (): void {
    Process::fake(['*' => Process::result(output: "ok\n")]);
    Process::preventStrayProcesses();

    (new SshRemoteShell)->run(nodeWithPinnedHostKeyForMetadata(), 'echo "$ORBIT_PROXY_SUFFIX"', [
        'metadata' => ['ORBIT_PROXY_SUFFIX' => '.backend'],
    ]);

    Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->command, 'export ORBIT_PROXY_SUFFIX='));
});

it('allows wg-easy database path metadata used by vpn state commands', function (): void {
    Process::fake();

    (new SshRemoteShell)->run(nodeWithPinnedHostKeyForMetadata(), 'test -n "$ORBIT_WG_EASY_DB_PATH"', [
        'metadata' => ['ORBIT_WG_EASY_DB_PATH' => '/home/orbit/.config/orbit/wg-easy/wg-easy.db'],
    ]);

    Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->command, 'export ORBIT_WG_EASY_DB_PATH='));
});
