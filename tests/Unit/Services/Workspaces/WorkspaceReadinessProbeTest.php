<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceReadinessProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('retries transient unhealthy workspace responses', function (): void {
    $probe = new WorkspaceReadinessProbe(maxAttempts: 3, retryDelayMilliseconds: 0);
    $attempts = 0;

    $result = $probe->probeWith(function () use (&$attempts): array {
        $attempts++;

        return $attempts < 3
            ? ['reachable' => false, 'status' => $attempts === 1 ? '500' : '502']
            : ['reachable' => true, 'status' => '200'];
    });

    expect($result)->toBe(['reachable' => true, 'status' => '200'])
        ->and($attempts)->toBe(3);
});

it('returns the last unhealthy readiness result after all attempts fail', function (): void {
    $probe = new WorkspaceReadinessProbe(maxAttempts: 2, retryDelayMilliseconds: 0);
    $attempts = 0;

    $result = $probe->probeWith(function () use (&$attempts): array {
        $attempts++;

        return ['reachable' => false, 'status' => $attempts === 1 ? '502' : 'error: Operation timed out'];
    });

    expect($result)->toBe(['reachable' => false, 'status' => 'error: Operation timed out'])
        ->and($attempts)->toBe(2);
});

it('keeps default readiness retries within the setup probe budget', function (): void {
    $probe = new WorkspaceReadinessProbe(retryDelayMilliseconds: 0);
    $attempts = 0;

    $result = $probe->probeWith(function () use (&$attempts): array {
        $attempts++;

        return ['reachable' => false, 'status' => '500'];
    });

    expect($result)->toBe(['reachable' => false, 'status' => '500'])
        ->and($attempts)->toBe(10);
});

it('does not retry non-transient workspace configuration failures', function (): void {
    $probe = new WorkspaceReadinessProbe(maxAttempts: 3, retryDelayMilliseconds: 0);
    $attempts = 0;

    $result = $probe->probeWith(function () use (&$attempts): array {
        $attempts++;

        return ['reachable' => false, 'status' => 'no_app'];
    });

    expect($result)->toBe(['reachable' => false, 'status' => 'no_app'])
        ->and($attempts)->toBe(1);
});

it('fails readiness when vite module assets are not reachable', function (): void {
    $workspace = workspaceForReadinessProbe();
    $url = $workspace->url();

    Http::preventStrayRequests();
    Http::fake([
        $url => Http::response(<<<HTML
            <html>
                <head>
                    <script type="module" src="{$url}/@vite/client"></script>
                    <script type="module" src="{$url}/resources/js/app.ts"></script>
                </head>
            </html>
            HTML),
        "{$url}/@vite/client" => Http::response('Not found', 404),
    ]);

    $result = (new WorkspaceReadinessProbe(maxAttempts: 1, retryDelayMilliseconds: 0))->probe($workspace);

    expect($result)->toBe(['reachable' => false, 'status' => 'asset_404']);
});

it('passes readiness when vite module assets are reachable', function (): void {
    $workspace = workspaceForReadinessProbe();
    $url = $workspace->url();
    $viteUrl = "{$url}:5186";

    Http::preventStrayRequests();
    Http::fake([
        $url => Http::response(<<<HTML
            <html>
                <head>
                    <script type="module" src="{$viteUrl}/@vite/client"></script>
                    <script type="module" src="{$viteUrl}/resources/js/app.ts"></script>
                </head>
            </html>
            HTML),
        "{$viteUrl}/@vite/client" => Http::response('ok'),
        "{$viteUrl}/resources/js/app.ts" => Http::response('ok'),
    ]);

    $result = (new WorkspaceReadinessProbe(maxAttempts: 1, retryDelayMilliseconds: 0))->probe($workspace);

    expect($result)->toBe(['reachable' => true, 'status' => '200']);
});

function workspaceForReadinessProbe(): Workspace
{
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App\Models\App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
    ]);

    return Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'feature',
    ]);
}
