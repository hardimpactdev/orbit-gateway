<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\FirewallRule;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const FIREWALL_RULE_MUTATION_CALLER_WG_IP = '10.6.0.99';

beforeEach(function (): void {
    app()->instance(RemoteShell::class, new FirewallRuleMutationControllerShell);
});

function createFirewallRuleMutationCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => FIREWALL_RULE_MUTATION_CALLER_WG_IP,
        'wireguard_address' => FIREWALL_RULE_MUTATION_CALLER_WG_IP,
        'platform' => 'ubuntu'], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantFirewallRuleMutationAccess(Node $caller, Node $servingNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'created_at' => now(),
        'updated_at' => now()]);
}

describe('FirewallRule mutation controllers', function (): void {
    it('stores firewall rule intent for authorized callers', function (): void {
        $caller = createFirewallRuleMutationCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1', 'platform' => 'ubuntu']);
        grantFirewallRuleMutationAccess($caller, $node);

        $response = $this->call('POST', '/api/firewall-rules', [
            'action' => 'allow',
            'name' => 'local-vite',
            'node' => 'app-1',
            'source' => '10.6.0.0/24',
            'port' => '5173',
            'protocol' => 'tcp'], [], [], ['REMOTE_ADDR' => FIREWALL_RULE_MUTATION_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.rule.name', 'local-vite')
            ->assertJsonPath('success.meta.backend_enacted', true)
            ->assertJsonPath('success.meta.warnings', []);

        expect(FirewallRule::query()->where('name', 'local-vite')->exists())->toBeTrue();
    });

    it('rejects unauthorized store requests without mutation', function (): void {
        createFirewallRuleMutationCallerNode();
        createTestAppHostNode(['name' => 'app-1', 'platform' => 'ubuntu']);

        $response = $this->call('POST', '/api/firewall-rules', [
            'action' => 'allow',
            'name' => 'local-vite',
            'node' => 'app-1',
            'port' => '5173'], [], [], ['REMOTE_ADDR' => FIREWALL_RULE_MUTATION_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect(FirewallRule::query()->count())->toBe(0);
    });

    it('requires destructive consent for delete requests', function (): void {
        createFirewallRuleMutationCallerNode(role: 'gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'platform' => 'ubuntu']);
        FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);

        $response = $this->call('DELETE', '/api/firewall-rules/local-vite?node=app-1', [], [], [], ['REMOTE_ADDR' => FIREWALL_RULE_MUTATION_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'destructive_consent_required');

        expect(FirewallRule::query()->where('name', 'local-vite')->exists())->toBeTrue();
    });

    it('removes firewall rule intent and cleans the backend synchronously', function (): void {
        createFirewallRuleMutationCallerNode(role: 'gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'platform' => 'ubuntu']);
        FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);

        $response = $this->call('DELETE', '/api/firewall-rules/local-vite?node=app-1&destructive_consent=1', [], [], [], ['REMOTE_ADDR' => FIREWALL_RULE_MUTATION_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.rule.status', 'removed_with_drift')
            ->assertJsonPath('success.meta.backend_removed', true)
            ->assertJsonPath('success.meta.warnings', []);

        expect(FirewallRule::query()->where('name', 'local-vite')->exists())->toBeFalse();
    });

    it('rejects protected firewall rule deletion through the API', function (): void {
        createFirewallRuleMutationCallerNode(role: 'gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'platform' => 'ubuntu']);
        FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'orbit-public-ssh-deny-v4',
            'action' => 'deny',
            'port' => '22',
            'address_family' => 'v4',
            'interface' => 'public',
            'owner' => 'node-security',
            'protected' => true]);

        $response = $this->call('DELETE', '/api/firewall-rules/orbit-public-ssh-deny-v4?node=app-1&destructive_consent=1', [], [], [], ['REMOTE_ADDR' => FIREWALL_RULE_MUTATION_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'firewall_rule.protected');

        expect(FirewallRule::query()->where('name', 'orbit-public-ssh-deny-v4')->exists())->toBeTrue();
    });
});

final class FirewallRuleMutationControllerShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
