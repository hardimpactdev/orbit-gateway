<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PROXY_ROUTE_MUTATION_CALLER_WG_IP = '10.6.0.92';

function createProxyRouteMutationCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => PROXY_ROUTE_MUTATION_CALLER_WG_IP,
        'wireguard_address' => PROXY_ROUTE_MUTATION_CALLER_WG_IP], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantProxyRouteMutationAccess(Node $caller, Node $servingNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'created_at' => now(),
        'updated_at' => now()]);
}

describe('ProxyRoute mutation API', function (): void {
    it('stores custom upstream route intent for authorized callers', function (): void {
        $caller = createProxyRouteMutationCallerNode();
        $servingNode = createTestAppHostNode(['name' => 'app-1']);
        grantProxyRouteMutationAccess($caller, $servingNode);

        $response = $this->withServerVariables(['REMOTE_ADDR' => PROXY_ROUTE_MUTATION_CALLER_WG_IP])->postJson('/api/proxy-routes', [
            'domain' => 'vite.docs.test',
            'node' => 'app-1',
            'upstream' => 'http://127.0.0.1:5173']);

        $response->assertOk()
            ->assertJsonPath('success.data.route.domain', 'vite.docs.test')
            ->assertJsonPath('success.meta.action', 'created')
            ->assertJsonPath('success.meta.warnings.0.code', 'proxy.enactment_deferred');
    });

    it('denies domain conflicts for non-custom routes', function (): void {
        createProxyRouteMutationCallerNode(role: 'gateway');
        $servingNode = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $servingNode->id]);

        ProxyRoute::factory()->create([
            'node_id' => $servingNode->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => PROXY_ROUTE_MUTATION_CALLER_WG_IP])->postJson('/api/proxy-routes', [
            'domain' => 'docs.test',
            'node' => 'app-1',
            'upstream' => 'http://127.0.0.1:5173',
            'force' => true]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'proxy.domain_conflict')
            ->assertJsonPath('error.meta.owner_type', 'app');
    });

    it('removes custom route intent with destructive consent', function (): void {
        createProxyRouteMutationCallerNode(role: 'gateway');
        $servingNode = createTestAppHostNode(['name' => 'app-1']);

        ProxyRoute::factory()->create([
            'node_id' => $servingNode->id,
            'domain' => 'old.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => ['target' => ['type' => 'redirect', 'value' => 'https://docs.test'], 'code' => 302]]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => PROXY_ROUTE_MUTATION_CALLER_WG_IP])->deleteJson('/api/proxy-routes/old.test', [
            'destructive_consent' => true]);

        $response->assertOk()
            ->assertJsonPath('success.data.route.domain', 'old.test')
            ->assertJsonPath('success.data.route.status', 'removed_with_drift')
            ->assertJsonPath('success.meta.warnings.0.code', 'proxy.cleanup_deferred');

        expect(ProxyRoute::query()->where('domain', 'old.test')->exists())->toBeFalse();
    });

    it('requires destructive consent before removing intent', function (): void {
        createProxyRouteMutationCallerNode(role: 'gateway');

        $response = $this->withServerVariables(['REMOTE_ADDR' => PROXY_ROUTE_MUTATION_CALLER_WG_IP])->deleteJson('/api/proxy-routes/old.test');

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'destructive_consent_required');
    });
});
