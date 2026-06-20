<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Convergence\UfwFirewallRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('plans ok when the remote ufw rule already matches gateway intent', function (): void {
    $node = Node::factory()->appDev()->create(['platform' => 'ubuntu']);
    $rule = FirewallRule::factory()->create([
        'node_id' => $node->id,
        'name' => 'local-vite',
        'source' => '10.6.0.0/24',
        'port' => '5173',
        'protocol' => 'tcp',
    ]);
    $convergence = UfwFirewallRule::fromRule($rule);
    $shell = new UfwFirewallRuleRecordingShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: <<<'OUT'
Status: active

     To                         Action      From
     --                         ------      ----
[ 1] 5173/tcp                   ALLOW IN    10.6.0.0/24

OUT,
            stderr: '',
            durationMs: 1,
        ),
    ]);

    $probe = $convergence->probe($node, $shell);
    $plan = $convergence->plan($probe);
    $result = $convergence->apply($node, $shell, $plan);

    expect($probe->reachable)->toBeTrue()
        ->and($probe->present)->toBeTrue()
        ->and($plan->status)->toBe(ConvergenceStatus::Ok)
        ->and($result->status)->toBe(ConvergenceStatus::Ok)
        ->and($result->changed())->toBeFalse()
        ->and($shell->scripts)->toHaveCount(1)
        ->and($shell->scripts[0])->toContain('sudo ufw status numbered');
});

it('applies a missing ufw rule through apply and reload scripts', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1', 'platform' => 'ubuntu']);
    $rule = FirewallRule::factory()->create([
        'node_id' => $node->id,
        'name' => 'local-vite',
        'source' => '10.6.0.0/24',
        'port' => '5173',
        'reason' => 'local development',
    ]);
    $convergence = UfwFirewallRule::fromRule($rule);
    $shell = new UfwFirewallRuleRecordingShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: "Status: active\n\nTo                         Action      From\n--                         ------      ----\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $probe = $convergence->probe($node, $shell);
    $plan = $convergence->plan($probe);
    $result = $convergence->apply($node, $shell, $plan);

    expect($plan->status)->toBe(ConvergenceStatus::Changed)
        ->and($plan->summary)->toContain('local-vite')
        ->and($result->status)->toBe(ConvergenceStatus::Changed)
        ->and($result->changed())->toBeTrue()
        ->and($shell->scripts[1])->toContain('sudo ufw allow in from')
        ->and($shell->scripts[1])->toContain("'10.6.0.0/24'")
        ->and($shell->scripts[1])->toContain("'local development'")
        ->and($shell->scripts[2])->toBe('sudo ufw reload');
});

it('deletes a partial match before re-applying gateway intent', function (): void {
    $node = Node::factory()->appDev()->create(['platform' => 'ubuntu']);
    $rule = FirewallRule::factory()->create([
        'node_id' => $node->id,
        'name' => 'local-vite',
        'source' => '10.6.0.0/24',
        'port' => '5173',
    ]);
    $convergence = UfwFirewallRule::fromRule($rule);
    $shell = new UfwFirewallRuleRecordingShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: <<<'OUT'
Status: active

     To                         Action      From
     --                         ------      ----
[ 1] 5173/tcp                   ALLOW IN    Anywhere

OUT,
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $probe = $convergence->probe($node, $shell);
    $plan = $convergence->plan($probe);
    $result = $convergence->apply($node, $shell, $plan);

    expect($probe->present)->toBeFalse()
        ->and($probe->partialMatch)->not->toBeNull()
        ->and($plan->status)->toBe(ConvergenceStatus::Changed)
        ->and($result->changed())->toBeTrue()
        ->and($shell->scripts[1])->toContain('sudo ufw delete allow in from')
        ->and($shell->scripts[2])->toContain('sudo ufw allow in from')
        ->and($shell->scripts[3])->toBe('sudo ufw reload');
});

it('reports unreachable when ufw introspection fails', function (): void {
    $node = Node::factory()->appDev()->create(['platform' => 'ubuntu']);
    $rule = FirewallRule::factory()->create(['node_id' => $node->id]);
    $convergence = UfwFirewallRule::fromRule($rule);
    $shell = new UfwFirewallRuleRecordingShell([
        new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'ssh: connection refused', durationMs: 1),
    ]);

    $probe = $convergence->probe($node, $shell);
    $plan = $convergence->plan($probe);

    expect($probe->reachable)->toBeFalse()
        ->and($probe->error)->toBe('ssh: connection refused')
        ->and($plan->status)->toBe(ConvergenceStatus::Unreachable);
});

final class UfwFirewallRuleRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(1, '', 'unexpected call', 1);
    }
}
