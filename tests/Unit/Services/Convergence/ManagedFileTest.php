<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\Node;
use App\Services\Convergence\ManagedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('plans ok when the remote managed file already matches intent', function (): void {
    $node = Node::factory()->create();
    $content = "grafana: enabled\n";
    $file = new ManagedFile(
        path: '/etc/orbit/grafana.yml',
        content: $content,
        mode: '0640',
    );
    $shell = new ManagedFileRecordingShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'exists' => true,
                'hash' => hash('sha256', $content),
                'mode' => '0640',
            ], JSON_THROW_ON_ERROR)."\n",
            stderr: '',
            durationMs: 1,
        ),
    ]);

    $probe = $file->probe($node, $shell);
    $plan = $file->plan($probe);
    $result = $file->apply($node, $shell, $plan);

    expect($probe->exists)->toBeTrue()
        ->and($probe->hash)->toBe(hash('sha256', $content))
        ->and($plan->status)->toBe(ConvergenceStatus::Ok)
        ->and($result->status)->toBe(ConvergenceStatus::Ok)
        ->and($result->changed())->toBeFalse()
        ->and($shell->scripts[0])->toContain('sudo test -f "$path"')
        ->and($shell->scripts[0])->toContain('sudo sha256sum "$path"')
        ->and($shell->scripts[0])->toContain("sudo stat -c '%a' \"\$path\"")
        ->and($shell->scripts)->toHaveCount(1);
});

it('applies a missing managed file through a redacted remote shell script', function (): void {
    $node = Node::factory()->create();
    $file = new ManagedFile(
        path: '/etc/orbit/secrets/app.env',
        content: "TOKEN=secret-value\n",
        mode: '0600',
        directoryMode: '0700',
        sensitive: true,
    );
    $shell = new ManagedFileRecordingShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'exists' => false,
                'hash' => null,
                'mode' => null,
            ], JSON_THROW_ON_ERROR)."\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $probe = $file->probe($node, $shell);
    $plan = $file->plan($probe);
    $result = $file->apply($node, $shell, $plan);

    expect($plan->status)->toBe(ConvergenceStatus::Changed)
        ->and($result->status)->toBe(ConvergenceStatus::Changed)
        ->and($result->changed())->toBeTrue()
        ->and($result->details['path'])->toBe('/etc/orbit/secrets/app.env')
        ->and($result->details)->not->toHaveKey('content')
        ->and($shell->scripts[1])->toContain("sudo install -d -m 0700 '/etc/orbit/secrets'")
        ->and($shell->scripts[1])->toContain("sudo chmod 0600 '/etc/orbit/secrets/app.env'")
        ->and($shell->scripts[1])->toContain(base64_encode("TOKEN=secret-value\n"))
        ->and($shell->scripts[1])->not->toContain('secret-value');
});

it('reports unreachable when probing the managed file cannot reach the node', function (): void {
    $node = Node::factory()->create();
    $file = new ManagedFile(
        path: '/etc/orbit/missing.conf',
        content: "enabled=true\n",
    );
    $shell = new ManagedFileRecordingShell([
        new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'ssh: connection refused', durationMs: 1),
    ]);

    $probe = $file->probe($node, $shell);
    $plan = $file->plan($probe);

    expect($probe->reachable)->toBeFalse()
        ->and($probe->error)->toBe('ssh: connection refused')
        ->and($plan->status)->toBe(ConvergenceStatus::Unreachable)
        ->and($plan->summary)->toBe('Could not inspect managed file /etc/orbit/missing.conf.');
});

final class ManagedFileRecordingShell implements RemoteShell
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
