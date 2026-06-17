<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Tools\ToolInstaller;
use App\Services\Tools\ToolsFixer;
use App\Services\Tools\ToolsProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @return list<string>
 */
function todo314OrbitCaddyMountTargets(): array
{
    return collect(OrbitCaddyContainer::default()->mounts())
        ->pluck('target')
        ->values()
        ->all();
}

function todo314PathIsReachableFromContainer(string $path): bool
{
    foreach (todo314OrbitCaddyMountTargets() as $target) {
        if ($target === $path || str_starts_with($path, rtrim($target, '/').'/')) {
            return true;
        }
    }

    return false;
}

describe('orbit-caddy container coverage of route renderer outputs', function (): void {
    it('proxies managed PHP app ingress artifacts to the FrankenPHP runtime container without mounting host paths', function (): void {
        $renderer = new ProxyRouteRenderer;
        $appNode = Node::factory()->create(['name' => 'web-1']);
        $app = App::factory()->for($appNode, 'node')->create([
            'name' => 'docs',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [[
                    'node_id' => $appNode->id,
                    'domain' => 'docs.test',
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/sites/docs/current/public',
                    'runtime_upstream' => 'http://orbit-app-docs:8080',
                    'php_socket' => null,
                    'source_hash' => str_repeat('a', 64),
                ]],
                'tls' => [
                    'cert_path' => '/home/orbit/.config/orbit/certs/docs.test.crt',
                    'key_path' => '/home/orbit/.config/orbit/certs/docs.test.key',
                ],
            ],
        ]);

        $artifact = $route->config['backend_artifacts'][0];

        expect(todo314PathIsReachableFromContainer($route->config['tls']['cert_path']))->toBeTrue()
            ->and(todo314PathIsReachableFromContainer($route->config['tls']['key_path']))->toBeTrue();

        expect($renderer->renderPrivateBackend($route, $artifact))
            ->toContain('reverse_proxy http://orbit-app-docs:8080')
            ->not->toContain('php_fastcgi')
            ->not->toContain('bind 10.6.0.21');
    });

    it('mounts the host roots referenced by managed workspace route artifacts', function (): void {
        $node = Node::factory()->create(['name' => 'dev-1']);
        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'document_root' => 'public',
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature-branch',
            'path' => '/home/orbit/workspaces/docs/feature-branch',
        ]);

        $documentRoot = rtrim($workspace->path, '/').'/public';
        $phpSocket = "/home/orbit/.config/orbit/php/{$workspace->name}.sock";
        $certPath = "/home/orbit/.config/orbit/certs/{$workspace->name}.{$app->name}.test.crt";
        $keyPath = "/home/orbit/.config/orbit/certs/{$workspace->name}.{$app->name}.test.key";

        expect(todo314PathIsReachableFromContainer($documentRoot))->toBeTrue()
            ->and(todo314PathIsReachableFromContainer($phpSocket))->toBeTrue()
            ->and(todo314PathIsReachableFromContainer($certPath))->toBeTrue()
            ->and(todo314PathIsReachableFromContainer($keyPath))->toBeTrue();
    });

    it('mounts the /etc/orbit fallback that custom proxy routes default TLS paths to', function (): void {
        $renderer = new ProxyRouteRenderer;
        $node = createTestAppHostNode(['name' => 'app-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://host.docker.internal:5173'],
                'upstream' => 'http://host.docker.internal:5173',
            ],
        ]);

        expect(todo314PathIsReachableFromContainer('/etc/orbit/certs/vite.docs.test.crt'))->toBeTrue()
            ->and(todo314PathIsReachableFromContainer('/etc/orbit/certs/vite.docs.test.key'))->toBeTrue();

        expect($renderer->render($route))
            ->toContain('tls /etc/orbit/certs/vite.docs.test.crt /etc/orbit/certs/vite.docs.test.key')
            ->toContain('reverse_proxy http://host.docker.internal:5173');
    });

    it('exposes host.docker.internal so custom proxy routes can reach the node loopback from the container', function (): void {
        expect(OrbitCaddyContainer::default()->extraHosts())
            ->toHaveKey('host.docker.internal')
            ->and(OrbitCaddyContainer::default()->extraHosts()['host.docker.internal'])
            ->toBe('host-gateway');
    });

    it('renders router routes without binding to a node-only WireGuard address', function (): void {
        $renderer = new ProxyRouteRenderer;
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    ['node_id' => 42, 'node' => 'web-1', 'url' => 'http://10.6.0.21:'.OrbitCaddyContainer::PrivateBackendPort],
                ],
            ],
        ]);

        expect($renderer->renderRouterRoute($route))
            ->toContain('http://docs.test {')
            ->toContain('reverse_proxy http://10.6.0.21:'.OrbitCaddyContainer::PrivateBackendPort)
            ->not->toContain('bind 10.6.0.2');
    });

    it('renders private backend listeners on an internal port that ingress does not publish publicly', function (): void {
        $renderer = new ProxyRouteRenderer;
        $appNode = Node::factory()->create(['name' => 'web-1']);
        $app = App::factory()->for($appNode, 'node')->create([
            'name' => 'docs',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [[
                    'node_id' => $appNode->id,
                    'domain' => 'docs.test',
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/sites/docs/current/public',
                    'runtime_upstream' => 'http://orbit-app-docs:8080',
                    'php_socket' => null,
                    'source_hash' => str_repeat('a', 64),
                ]],
            ],
        ]);

        $coLocated = OrbitCaddyContainer::forPublicIngress('10.6.0.21');
        $privateOnlyPort = (string) OrbitCaddyContainer::PrivateBackendPort;
        $publicPorts = collect($coLocated->publishedPorts())
            ->reject(fn (string $port): bool => str_starts_with($port, '10.6.0.21:'))
            ->values()
            ->all();

        foreach ($publicPorts as $publicPort) {
            expect($publicPort)->not->toContain($privateOnlyPort);
        }

        expect($coLocated->publishedPorts())->toContain("10.6.0.21:{$privateOnlyPort}:{$privateOnlyPort}");

        expect($renderer->renderPrivateBackend($route, $route->config['backend_artifacts'][0]))
            ->toContain("http://docs.test:{$privateOnlyPort} {")
            ->not->toContain('http://docs.test {');
    });

    it('normalizes loopback upstreams written by tool route writers so persisted routes still reach the host', function (): void {
        $rewriter = function (string $methodName): string {
            $reflection = new ReflectionClass(ToolsFixer::class);
            $method = $reflection->getMethod($methodName);
            $instance = $reflection->newInstanceWithoutConstructor();
            $method->setAccessible(true);

            /** @var array{upstream: string} $config */
            $config = $method->invoke($instance, 'agent-ide');

            return $config['upstream'];
        };

        expect($rewriter('agentProxyRouteConfig'))
            ->toBe('http://host.docker.internal:8080')
            ->not->toContain('127.0.0.1');

        $probeReflection = new ReflectionClass(ToolsProbe::class);
        $probeMethod = $probeReflection->getMethod('agentProxyRouteConfig');
        $probeInstance = $probeReflection->newInstanceWithoutConstructor();
        $probeMethod->setAccessible(true);
        $probeConfig = $probeMethod->invoke($probeInstance, 'agent-ide');

        expect($probeConfig['upstream'])->toBe('http://host.docker.internal:8080');

        $installerReflection = new ReflectionClass(ToolInstaller::class);
        $installerMethod = $installerReflection->getMethod('createToolProxyRoute');
        expect($installerMethod)->toBeInstanceOf(ReflectionMethod::class);
    });

    it('rewrites pre-existing loopback custom proxy routes when rendering them through orbit-caddy', function (): void {
        $renderer = new ProxyRouteRenderer;
        $node = Node::factory()->create(['name' => 'gateway-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'tool.docs.test',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:8080'],
                'upstream' => 'http://127.0.0.1:8080',
                'owner_name' => 'agent-ide',
            ],
        ]);

        $content = $renderer->render($route);

        expect($content)
            ->toContain('reverse_proxy http://host.docker.internal:8080')
            ->not->toContain('127.0.0.1');
    });
});
