<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Firewall\FirewallRuleFixer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('FirewallRuleFixer', function (): void {
    it('re-applies missing firewall rules from gateway intent', function (): void {
        $node = Node::factory()->appDev()->create(['name' => 'app-1', 'platform' => 'ubuntu']);
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
            'reason' => 'local development',
        ]);
        $shell = new FirewallFixerRecordingRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "Status: active\n\nTo                         Action      From\n--                         ------      ----\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);

        $action = (new FirewallRuleFixer($shell))->fix($rule, new DriftEntry(
            family: 'firewall_rule',
            key: 'firewall_rule.rule_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));

        expect($action)->toMatchArray([
            'family' => 'firewall_rule',
            'node' => 'app-1',
            'status' => 'completed',
        ])
            ->and($shell->scripts[1])->toContain('sudo ufw allow in from')
            ->and($shell->scripts[1])->toContain("'10.6.0.0/24'")
            ->and($shell->scripts[2])->toBe('sudo ufw reload');
    });

    it('deletes mismatched observed rules before re-applying intent', function (): void {
        $node = Node::factory()->appDev()->create(['name' => 'app-1', 'platform' => 'ubuntu']);
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
        ]);
        $shell = new FirewallFixerRecordingRemoteShell([
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

        (new FirewallRuleFixer($shell))->fix($rule, new DriftEntry(
            family: 'firewall_rule',
            key: 'firewall_rule.rule_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
            detail: [
                'observed' => [
                    'direction' => 'incoming',
                    'action' => 'allow',
                    'source' => 'any',
                    'destination' => null,
                    'port' => '5173',
                    'protocol' => 'tcp',
                ],
            ],
        ));

        expect($shell->scripts[1])->toContain('sudo ufw delete allow in from')
            ->and($shell->scripts[2])->toContain('sudo ufw allow in from');
    });
});

final class FirewallFixerRecordingRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
