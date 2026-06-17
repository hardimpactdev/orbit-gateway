<?php

declare(strict_types=1);

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App as OrbitApp;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\Workspace;
use App\Services\Authorization\ServingNodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

#[RequiresPermission('node:remove')]
final class DefaultRequiresPermissionFixture {}

#[RequiresPermission('workspace:setup', servingNode: ServingNode::WorkspaceOwning)]
final class WorkspaceRequiresPermissionFixture {}

function servingNodeRequest(array $routeParameters = [], array $input = []): Request
{
    $request = Request::create('/test', 'POST', $input);
    $route = new Route(['POST'], '/test', []);
    $route->bind($request);

    foreach ($routeParameters as $name => $value) {
        $route->setParameter($name, $value);
    }

    $request->setRouteResolver(static fn (): Route => $route);

    return $request;
}

describe('RequiresPermission attribute', function (): void {
    it('defaults to target serving-node resolution', function (): void {
        $attributes = (new ReflectionClass(DefaultRequiresPermissionFixture::class))
            ->getAttributes(RequiresPermission::class);

        $attribute = $attributes[0]->newInstance();

        expect($attribute->permission)->toBe('node:remove')
            ->and($attribute->servingNode)->toBe(ServingNode::Target);
    });

    it('stores explicit serving-node resolution', function (): void {
        $attributes = (new ReflectionClass(WorkspaceRequiresPermissionFixture::class))
            ->getAttributes(RequiresPermission::class);

        $attribute = $attributes[0]->newInstance();

        expect($attribute->permission)->toBe('workspace:setup')
            ->and($attribute->servingNode)->toBe(ServingNode::WorkspaceOwning);
    });
});

describe('ServingNodeResolver', function (): void {
    it('resolves the active gateway node', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1']);

        $resolved = (new ServingNodeResolver)->resolve(servingNodeRequest(), ServingNode::Gateway);

        expect($resolved?->is($gateway))->toBeTrue();
    });

    it('resolves target nodes from route parameters', function (): void {
        $target = Node::factory()->create(['name' => 'app-1']);

        $resolved = (new ServingNodeResolver)->resolve(
            servingNodeRequest(['name' => 'app-1']),
            ServingNode::Target,
        );

        expect($resolved?->is($target))->toBeTrue();
    });

    it('resolves app-owning nodes from app parameters', function (): void {
        $node = Node::factory()->create(['name' => 'app-node']);
        OrbitApp::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $resolved = (new ServingNodeResolver)->resolve(
            servingNodeRequest(['app' => 'docs']),
            ServingNode::AppOwning,
        );

        expect($resolved?->is($node))->toBeTrue();
    });

    it('resolves app-owning nodes from process identity', function (): void {
        $node = Node::factory()->create(['name' => 'process-node']);
        $app = OrbitApp::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        OrbitProcess::factory()->forOwner($app)->create(['name' => 'queue']);

        $resolved = (new ServingNodeResolver)->resolve(
            servingNodeRequest(['name' => 'queue']),
            ServingNode::AppOwning,
        );

        expect($resolved?->is($node))->toBeTrue();
    });

    it('resolves workspace-owning nodes from workspace and app parameters', function (): void {
        $node = Node::factory()->create(['name' => 'docs-node']);
        $otherNode = Node::factory()->create(['name' => 'other-node']);
        $app = OrbitApp::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $otherApp = OrbitApp::factory()->create(['name' => 'other', 'node_id' => $otherNode->id]);

        Workspace::factory()->create(['app_id' => $app->id, 'name' => 'feature']);
        Workspace::factory()->create(['app_id' => $otherApp->id, 'name' => 'feature']);

        $resolved = (new ServingNodeResolver)->resolve(
            servingNodeRequest(['workspace' => 'feature'], ['app' => 'docs']),
            ServingNode::WorkspaceOwning,
        );

        expect($resolved?->is($node))->toBeTrue();
    });

    it('resolves the caller node from the request user', function (): void {
        $caller = Node::factory()->create(['name' => 'caller-1']);
        $request = servingNodeRequest();
        $request->setUserResolver(static fn (): Node => $caller);

        $resolved = (new ServingNodeResolver)->resolve($request, ServingNode::Caller);

        expect($resolved?->is($caller))->toBeTrue();
    });

    it('returns null when the serving node cannot be resolved', function (): void {
        $resolved = (new ServingNodeResolver)->resolve(servingNodeRequest(), ServingNode::Target);

        expect($resolved)->toBeNull();
    });
});
