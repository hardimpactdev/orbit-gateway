<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Services\Updates\UnattendedUpgradesDriver;
use App\Services\Updates\UpdateTarget;

it('supports managed Ubuntu node update targets only', function (): void {
    $driver = new UnattendedUpgradesDriver(new UnattendedUpgradesDriverShell);
    $node = Node::factory()->make();

    expect($driver->supports(new UpdateTarget('node', $node, 'ubuntu_24-04', 'managed-server-node')))->toBeTrue()
        ->and($driver->supports(new UpdateTarget('node', $node, 'ubuntu_26-04', 'managed-server-node')))->toBeTrue()
        ->and($driver->supports(new UpdateTarget('node', $node, 'ubuntu_24-04', 'unsupported-node')))->toBeFalse()
        ->and($driver->supports(new UpdateTarget('node', $node, 'macos_15', 'managed-server-node')))->toBeFalse()
        ->and($driver->supports(new UpdateTarget('app', $node, 'ubuntu_24-04', 'managed-server-node')))->toBeFalse();
});

it('reports healthy posture when unattended-upgrades is installed and clean', function (): void {
    $snapshot = probeSnapshot([
        'installed' => true,
        'auto_exists' => true,
        'unattended_exists' => true,
        'auto_hash_ok' => true,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 0,
        'last_run_status' => 'completed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ]);

    expect($snapshot->driver)->toBe('unattended-upgrades')
        ->and($snapshot->issues)->toBe([]);
});

it('reports missing config when the package or apt files are absent', function (array $facts): void {
    $issue = probeSnapshot($facts)->issues[0];

    expect($issue->code)->toBe('node.updates_config_missing')
        ->and($issue->kind)->toBe(DriftKind::Missing)
        ->and($issue->restorable)->toBeTrue()
        ->and($issue->detail['driver'])->toBe('unattended-upgrades');
})->with([
    'package missing' => [[
        'installed' => false,
        'auto_exists' => true,
        'unattended_exists' => true,
        'auto_hash_ok' => true,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 0,
        'last_run_status' => 'completed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ]],
    'auto config missing' => [[
        'installed' => true,
        'auto_exists' => false,
        'unattended_exists' => true,
        'auto_hash_ok' => false,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 0,
        'last_run_status' => 'completed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ]],
    'unattended config missing' => [[
        'installed' => true,
        'auto_exists' => true,
        'unattended_exists' => false,
        'auto_hash_ok' => true,
        'unattended_hash_ok' => false,
        'dry_run_exit' => 0,
        'last_run_status' => 'completed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ]],
]);

it('reports config mismatch when expected apt config hashes differ', function (): void {
    $issue = probeSnapshot([
        'installed' => true,
        'auto_exists' => true,
        'unattended_exists' => true,
        'auto_hash_ok' => false,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 0,
        'last_run_status' => 'completed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ])->issues[0];

    expect($issue->code)->toBe('node.updates_config_mismatch')
        ->and($issue->kind)->toBe(DriftKind::Divergent)
        ->and($issue->restorable)->toBeTrue();
});

it('reports dry-run failure', function (): void {
    $issue = probeSnapshot([
        'installed' => true,
        'auto_exists' => true,
        'unattended_exists' => true,
        'auto_hash_ok' => true,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 1,
        'last_run_status' => 'completed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ])->issues[0];

    expect($issue->code)->toBe('node.updates_dry_run_failed')
        ->and($issue->kind)->toBe(DriftKind::Unverifiable)
        ->and($issue->detail['dry_run_exit'])->toBe(1);
});

it('runs the unattended-upgrade dry-run only after expected config is present', function (): void {
    $shell = new UnattendedUpgradesDriverShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'installed' => true,
                'auto_exists' => false,
                'unattended_exists' => false,
                'auto_hash_ok' => false,
                'unattended_hash_ok' => false,
                'dry_run_exit' => 127,
                'last_run_status' => 'unknown',
                'reboot_required' => false,
                'reboot_required_packages' => [],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        ),
    ]);

    (new UnattendedUpgradesDriver($shell))->probe(updateTarget());

    expect($shell->scripts[0])
        ->toContain('$dryRunExit = null;')
        ->toContain('$configReady = $autoExists && $unattendedExists && $autoHashOk && $unattendedHashOk;')
        ->toContain('if ($installed && $configReady) {')
        ->not->toContain('if ($installed) {');
});

it('reports latest unattended-upgrades run failure', function (): void {
    $issue = probeSnapshot([
        'installed' => true,
        'auto_exists' => true,
        'unattended_exists' => true,
        'auto_hash_ok' => true,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 0,
        'last_run_status' => 'failed',
        'reboot_required' => false,
        'reboot_required_packages' => [],
    ])->issues[0];

    expect($issue->code)->toBe('node.updates_last_run_failed')
        ->and($issue->kind)->toBe(DriftKind::Divergent)
        ->and($issue->detail['last_run_status'])->toBe('failed');
});

it('reports reboot-required drift with package names', function (): void {
    $issue = probeSnapshot([
        'installed' => true,
        'auto_exists' => true,
        'unattended_exists' => true,
        'auto_hash_ok' => true,
        'unattended_hash_ok' => true,
        'dry_run_exit' => 0,
        'last_run_status' => 'completed',
        'reboot_required' => true,
        'reboot_required_packages' => ['linux-image-6.8.0-60-generic'],
    ])->issues[0];

    expect($issue->code)->toBe('node.updates_reboot_required')
        ->and($issue->kind)->toBe(DriftKind::Divergent)
        ->and($issue->restorable)->toBeFalse()
        ->and($issue->summary)->toBe('This node requires an explicit reboot to finish installed updates. Orbit will not reboot it automatically. Reboot this server as soon as possible.')
        ->and($issue->detail['reboot_required_packages'])->toBe(['linux-image-6.8.0-60-generic']);
});

it('reports unverifiable posture when the shell probe fails', function (): void {
    $shell = new UnattendedUpgradesDriverShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
    ]);

    $snapshot = (new UnattendedUpgradesDriver($shell))->probe(updateTarget());
    $issue = $snapshot->issues[0];

    expect($issue->code)->toBe('node.updates_unverifiable')
        ->and($issue->kind)->toBe(DriftKind::Unverifiable)
        ->and($issue->restorable)->toBeTrue()
        ->and($issue->detail['stderr'])->toBe('permission denied');
});

it('repairs configuration and runs unattended-upgrade during apply', function (): void {
    $shell = new UnattendedUpgradesDriverShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        managedFileProbeResult(exists: false),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        managedFileProbeResult(exists: true, hash: str_repeat('b', 64), mode: '0644'),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: 'completed', stderr: '', durationMs: 1),
    ]);

    $result = (new UnattendedUpgradesDriver($shell))->apply(updateTarget());

    expect($result->status)->toBe('completed')
        ->and($result->driver)->toBe('unattended-upgrades')
        ->and($shell->scripts)->toHaveCount(6)
        ->and($shell->scripts[0])->toContain('install -y -qq unattended-upgrades')
        ->and($shell->scripts[1])->toContain('/etc/apt/apt.conf.d/20auto-upgrades')
        ->and($shell->scripts[2])->toContain('/etc/apt/apt.conf.d/20auto-upgrades')
        ->and($shell->scripts[3])->toContain('/etc/apt/apt.conf.d/50unattended-upgrades')
        ->and($shell->scripts[4])->toContain('/etc/apt/apt.conf.d/50unattended-upgrades')
        ->and($shell->scripts[5])->toBe('sudo unattended-upgrade')
        ->and($shell->options[5])->toMatchArray([
            'timeout' => 900,
            'throw' => false,
        ]);
});

it('does not run unattended-upgrade when config repair fails', function (): void {
    $shell = new UnattendedUpgradesDriverShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'apt failed', durationMs: 1),
    ]);

    $result = (new UnattendedUpgradesDriver($shell))->apply(updateTarget());

    expect($result->status)->toBe('failed')
        ->and($result->summary)->toContain('Failed to install unattended security upgrades')
        ->and($shell->scripts)->toHaveCount(1);
});

function probeSnapshot(array $facts)
{
    $shell = new UnattendedUpgradesDriverShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode($facts, JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        ),
    ]);

    return (new UnattendedUpgradesDriver($shell))->probe(updateTarget());
}

function updateTarget(): UpdateTarget
{
    return new UpdateTarget(
        family: 'node',
        node: Node::factory()->make(['platform' => 'ubuntu_24-04']),
        platform: 'ubuntu_24-04',
        scope: 'managed-server-node',
    );
}

function managedFileProbeResult(bool $exists, ?string $hash = null, ?string $mode = null): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode([
            'exists' => $exists,
            'hash' => $hash,
            'mode' => $mode,
        ], JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 1,
    );
}

final class UnattendedUpgradesDriverShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results = []) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
