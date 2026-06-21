<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\Node;
use App\Services\Convergence\SystemdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('plans ok when the remote systemd service already matches intent', function (): void {
    $node = Node::factory()->create();
    $content = "[Unit]\nDescription=Orbit process node-exporter\n";
    $service = new SystemdService(
        unitName: 'node-exporter',
        content: $content,
    );
    $shell = new SystemdServiceRecordingShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'exists' => true,
                'hash' => hash('sha256', $content),
                'enabled' => true,
            ], JSON_THROW_ON_ERROR)."\n",
            stderr: '',
            durationMs: 1,
        ),
    ]);

    $probe = $service->probe($node, $shell);
    $plan = $service->plan($probe);
    $result = $service->apply($node, $shell, $plan);

    expect($probe->exists)->toBeTrue()
        ->and($probe->enabled)->toBeTrue()
        ->and($probe->hash)->toBe(hash('sha256', $content))
        ->and($plan->status)->toBe(ConvergenceStatus::Ok)
        ->and($result->status)->toBe(ConvergenceStatus::Ok)
        ->and($result->changed())->toBeFalse()
        ->and($shell->scripts[0])->toContain('sudo test -f "$path"')
        ->and($shell->scripts[0])->toContain('sudo systemctl is-enabled "$service"')
        ->and($shell->scripts)->toHaveCount(1);
});

it('applies a missing systemd service unit and enables it', function (): void {
    $node = Node::factory()->create();
    $content = "[Unit]\nDescription=Orbit process opencode-server\n";
    $service = new SystemdService(
        unitName: 'opencode-server',
        content: $content,
    );
    $shell = new SystemdServiceRecordingShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'exists' => false,
                'hash' => null,
                'enabled' => false,
            ], JSON_THROW_ON_ERROR)."\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $probe = $service->probe($node, $shell);
    $plan = $service->plan($probe);
    $result = $service->apply($node, $shell, $plan);

    expect($plan->status)->toBe(ConvergenceStatus::Changed)
        ->and($plan->details)->toMatchArray([
            'service' => 'opencode-server.service',
            'path' => '/etc/systemd/system/opencode-server.service',
            'expected_hash' => hash('sha256', $content),
            'enabled' => true,
            'observed_hash' => null,
            'observed_enabled' => false,
        ])
        ->and($result->status)->toBe(ConvergenceStatus::Changed)
        ->and($result->changed())->toBeTrue()
        ->and($shell->scripts[1])->toContain('sudo install -d -m 0755 /etc/systemd/system')
        ->and($shell->scripts[1])->toContain("sudo tee '/etc/systemd/system/opencode-server.service' >/dev/null")
        ->and($shell->scripts[1])->toContain("sudo chmod 0644 '/etc/systemd/system/opencode-server.service'")
        ->and($shell->scripts[1])->toContain('sudo systemctl daemon-reload')
        ->and($shell->scripts[1])->toContain("sudo systemctl enable 'opencode-server.service' >/dev/null")
        ->and($shell->scripts[1])->toContain(base64_encode($content))
        ->and($shell->scripts[1])->not->toContain($content);
});

it('plans a changed systemd service when it is disabled', function (): void {
    $node = Node::factory()->create();
    $content = "[Unit]\nDescription=Orbit process queue\n";
    $service = new SystemdService(
        unitName: 'orbit_docs_main_queue',
        content: $content,
    );
    $shell = new SystemdServiceRecordingShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'exists' => true,
                'hash' => hash('sha256', $content),
                'enabled' => false,
            ], JSON_THROW_ON_ERROR)."\n",
            stderr: '',
            durationMs: 1,
        ),
    ]);

    $plan = $service->plan($service->probe($node, $shell));

    expect($plan->status)->toBe(ConvergenceStatus::Changed)
        ->and($plan->summary)->toBe('Enable systemd service orbit_docs_main_queue.service.')
        ->and($plan->details)->toMatchArray([
            'service' => 'orbit_docs_main_queue.service',
            'observed_enabled' => false,
        ]);
});

it('reports unreachable when probing the systemd service cannot reach the node', function (): void {
    $node = Node::factory()->create();
    $service = new SystemdService(
        unitName: 'node-exporter',
        content: "[Unit]\nDescription=Orbit process node-exporter\n",
    );
    $shell = new SystemdServiceRecordingShell([
        new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'ssh: connection refused', durationMs: 1),
    ]);

    $probe = $service->probe($node, $shell);
    $plan = $service->plan($probe);

    expect($probe->reachable)->toBeFalse()
        ->and($probe->error)->toBe('ssh: connection refused')
        ->and($plan->status)->toBe(ConvergenceStatus::Unreachable)
        ->and($plan->summary)->toBe('Could not inspect systemd service node-exporter.service.');
});

final class SystemdServiceRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(1, '', 'unexpected call', 1);
    }
}
