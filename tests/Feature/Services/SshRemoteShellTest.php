<?php

declare(strict_types=1);

use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\RemoteShell\SshRemoteShell;
use Illuminate\Contracts\Process\ProcessResult as ProcessResultContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('runs local nodes through bash without ssh', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ok\n"),
    ]);

    $node = Node::factory()->create([
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    $result = (new SshRemoteShell)->run($node, 'pwd', [
        'cwd' => '/srv/example',
        'metadata' => ['ORBIT_NODE_ID' => 'testing'],
        'timeout' => 45,
    ]);

    expect($result->successful())->toBeTrue()
        ->and($result->stdout)->toBe("ok\n");

    Process::assertRan(function (PendingProcess $process, ProcessResultContract $processResult): bool {
        return $process->command === "bash -c 'export ORBIT_NODE_ID='\\''testing'\\''; cd '\\''/srv/example'\\'' && pwd'"
            && $process->timeout === 45
            && $processResult->output() === "ok\n";
    });
});

it('runs nodes with an assigned gateway role through bash without ssh', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ok\n"),
    ]);

    $node = Node::factory()->create([
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    $result = (new SshRemoteShell)->run($node, 'pwd');

    expect($result->successful())->toBeTrue()
        ->and($result->stdout)->toBe("ok\n");

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === "bash -c 'pwd'");
});

it('runs gateway host work over ssh when dispatched from orbit-gateway', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ok\n"),
    ]);

    $previousHostPath = getenv('ORBIT_HOST_PATH');
    $previousSourcePath = getenv('ORBIT_SOURCE_PATH');
    putenv('ORBIT_HOST_PATH');
    putenv('ORBIT_SOURCE_PATH=/opt/orbit');

    try {
        $node = Node::factory()->create([
            'host' => 'gateway.example.com',
            'wireguard_address' => '10.6.0.2',
            'user' => 'orbit',
            ...sshRemoteShellPinnedHostKey(),
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);

        $result = (new SshRemoteShell)->run($node, 'pwd');

        expect($result->successful())->toBeTrue();

        Process::assertRan(function (PendingProcess $process): bool {
            $command = (string) $process->command;

            return str_contains($command, 'ssh -o StrictHostKeyChecking=yes')
                && str_contains($command, "'orbit'@'10.6.0.2'")
                && str_contains($command, 'bash -lc')
                && ! str_starts_with($command, 'bash -c ');
        });
    } finally {
        if ($previousHostPath === false) {
            putenv('ORBIT_HOST_PATH');
        } else {
            putenv("ORBIT_HOST_PATH={$previousHostPath}");
        }

        if ($previousSourcePath === false) {
            putenv('ORBIT_SOURCE_PATH');
        } else {
            putenv("ORBIT_SOURCE_PATH={$previousSourcePath}");
        }
    }
});

it('rejects invalid metadata keys before composing shell commands', function (): void {
    Process::preventStrayProcesses();
    Process::fake();

    $node = Node::factory()->create([
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    expect(fn () => (new SshRemoteShell)->run($node, 'pwd', [
        'metadata' => ['APP_ENV; touch /tmp/orbit-pwned' => 'testing'],
    ]))->toThrow(InvalidArgumentException::class, 'Remote shell metadata key');

    Process::assertRanTimes(fn (): bool => true, 0);
});

it('runs remote nodes over ssh using wireguard address and steady state user', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "cloned\n"),
    ]);

    $node = Node::factory()->create([
        'host' => 'public.example.com',
        'wireguard_address' => '10.44.0.20',
        'user' => 'deploy',
        ...sshRemoteShellPinnedHostKey(),
    ]);

    $result = (new SshRemoteShell)->run($node, 'git clone git@github.com:acme/site.git site');

    expect($result->successful())->toBeTrue()
        ->and($result->stdout)->toBe("cloned\n");

    Process::assertRan(function (PendingProcess $process): bool {
        return str_contains((string) $process->command, 'ssh -o StrictHostKeyChecking=yes')
            && str_contains((string) $process->command, '-o UserKnownHostsFile=')
            && str_contains((string) $process->command, '-o GlobalKnownHostsFile=')
            && str_contains((string) $process->command, '-o UpdateHostKeys=no')
            && str_contains((string) $process->command, "'deploy'@'10.44.0.20'")
            && str_contains((string) $process->command, 'bash -lc')
            && str_contains((string) $process->command, 'git clone git@github.com:acme/site.git site');
    });
});

it('uses the host in Docker topology runs', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ok\n"),
    ]);

    putenv('ORBIT_E2E_TOPOLOGY_PROVIDER=docker');

    try {
        $node = Node::factory()->create([
            'host' => 'dev',
            'wireguard_address' => '10.6.0.4',
            'user' => 'deploy',
            ...sshRemoteShellPinnedHostKey(),
        ]);

        (new SshRemoteShell)->run($node, 'hostname');

        Process::assertRan(function (PendingProcess $process): bool {
            return str_contains((string) $process->command, "'deploy'@'dev'")
                && ! str_contains((string) $process->command, "'deploy'@'10.6.0.4'");
        });
    } finally {
        putenv('ORBIT_E2E_TOPOLOGY_PROVIDER');
    }
});

it('uses the host when the Docker topology provider is loaded from laravel env', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ok\n"),
    ]);

    $previousServer = $_SERVER['ORBIT_E2E_TOPOLOGY_PROVIDER'] ?? null;
    $previousEnv = $_ENV['ORBIT_E2E_TOPOLOGY_PROVIDER'] ?? null;
    putenv('ORBIT_E2E_TOPOLOGY_PROVIDER');
    $_ENV['ORBIT_E2E_TOPOLOGY_PROVIDER'] = 'docker';
    $_SERVER['ORBIT_E2E_TOPOLOGY_PROVIDER'] = 'docker';

    try {
        $node = Node::factory()->create([
            'host' => 'dev',
            'wireguard_address' => '10.6.0.4',
            'user' => 'deploy',
            ...sshRemoteShellPinnedHostKey(),
        ]);

        (new SshRemoteShell)->run($node, 'hostname');

        Process::assertRan(function (PendingProcess $process): bool {
            return str_contains((string) $process->command, "'deploy'@'dev'")
                && ! str_contains((string) $process->command, "'deploy'@'10.6.0.4'");
        });
    } finally {
        if ($previousEnv === null) {
            unset($_ENV['ORBIT_E2E_TOPOLOGY_PROVIDER']);
        } else {
            $_ENV['ORBIT_E2E_TOPOLOGY_PROVIDER'] = $previousEnv;
        }

        if ($previousServer === null) {
            unset($_SERVER['ORBIT_E2E_TOPOLOGY_PROVIDER']);
        } else {
            $_SERVER['ORBIT_E2E_TOPOLOGY_PROVIDER'] = $previousServer;
        }
    }
});

it('uses the wireguard address by default outside Docker topology runs', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ok\n"),
    ]);

    putenv('ORBIT_E2E_TOPOLOGY_PROVIDER');

    $node = Node::factory()->create([
        'host' => 'dev',
        'wireguard_address' => '10.6.0.4',
        'user' => 'deploy',
        ...sshRemoteShellPinnedHostKey(),
    ]);

    (new SshRemoteShell)->run($node, 'hostname');

    Process::assertRan(function (PendingProcess $process): bool {
        return str_contains((string) $process->command, "'deploy'@'10.6.0.4'")
            && ! str_contains((string) $process->command, "'deploy'@'dev'");
    });
});

it('falls back to ssh user when steady state user is not recorded', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ok\n"),
    ]);

    $node = Node::factory()->create([
        'wireguard_address' => '10.44.0.21',
        'user' => null,
        ...sshRemoteShellPinnedHostKey(),
    ]);

    (new SshRemoteShell)->run($node, 'whoami');

    Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->command, "'orbit'@'10.44.0.21'"));
});

it('throws failed remote shell results when requested', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: "permission denied\n",
            exitCode: 13,
        ),
    ]);

    $node = Node::factory()->create([
        'name' => 'app-a',
        'wireguard_address' => null,
        'host' => 'app-a.internal',
        ...sshRemoteShellPinnedHostKey(),
    ]);

    expect(fn () => (new SshRemoteShell)->run($node, 'mkdir /srv/example', ['throw' => true]))
        ->toThrow(RemoteShellFailed::class, 'RemoteShell failed on app-a (exit 13): permission denied');
});

it('audits remote shell executions without raw scripts or output', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "secret-output\n"),
    ]);

    $node = Node::factory()->create([
        'name' => 'app-audit',
        'wireguard_address' => '10.44.0.22',
        'host' => 'app-audit.internal',
        ...sshRemoteShellPinnedHostKey(),
    ]);

    (new SshRemoteShell)->run($node, 'printf "%s" "raw-command-secret"', [
        'metadata' => ['ORBIT_REQUEST_ID' => 'req-123'],
        'input' => 'stdin-secret',
        'timeout' => 33,
    ]);

    $activity = DB::table('activity_log')
        ->where('event', 'remote_shell.run')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull();

    $properties = json_decode((string) $activity->properties, true, flags: JSON_THROW_ON_ERROR);

    expect($activity->log_name)->toBe('remote_shell')
        ->and($properties)->toMatchArray([
            'type' => 'remote_execution',
            'node' => 'app-audit',
            'metadata_keys' => ['ORBIT_REQUEST_ID'],
            'timeout' => 33,
            'exit_code' => 0,
            'status' => 'succeeded',
        ])
        ->and($properties)->toHaveKeys(['script_sha256', 'input_sha256'])
        ->and((string) $activity->properties)->not->toContain('raw-command-secret')
        ->and((string) $activity->properties)->not->toContain('secret-output')
        ->and((string) $activity->properties)->not->toContain('stdin-secret');
});

/**
 * @return array<string, mixed>
 */
function sshRemoteShellPinnedHostKey(): array
{
    return [
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAISshRemoteShellPinnedKey',
        'host_key_fingerprint' => 'SHA256:ssh-remote-shell',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ];
}
