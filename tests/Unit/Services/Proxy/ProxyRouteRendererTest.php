<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Proxy\ProxyRouteRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('ProxyRouteRenderer', function (): void {
    it('renders custom upstream routes as Caddy sites with Orbit TLS paths and normalizes host loopback for container reachability', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)->toContain('vite.docs.test {')
            ->and($content)->toContain('tls /etc/orbit/certs/vite.docs.test.crt /etc/orbit/certs/vite.docs.test.key')
            ->and($content)->toContain('reverse_proxy http://host.docker.internal:5173')
            ->and($content)->not->toContain('127.0.0.1')
            ->and((new ProxyRouteRenderer)->sourceHash($route))->toBe(hash('sha256', $content));
    });

    it('normalizes localhost upstreams to the orbit-caddy host gateway hostname', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'mail.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://localhost:8025'], 'upstream' => 'http://localhost:8025'],
        ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)
            ->toContain('reverse_proxy http://host.docker.internal:8025')
            ->not->toContain('http://localhost')
            ->not->toContain('127.0.0.1');
    });

    it('leaves non-loopback upstreams untouched', function (): void {
        expect(ProxyRouteRenderer::normalizeHostLoopback('http://10.6.0.21:80'))->toBe('http://10.6.0.21:80')
            ->and(ProxyRouteRenderer::normalizeHostLoopback('https://example.com:443/api'))->toBe('https://example.com:443/api')
            ->and(ProxyRouteRenderer::normalizeHostLoopback('http://127.0.0.1.example.com:80'))->toBe('http://127.0.0.1.example.com:80');
    });

    it('renders custom redirect routes with redirect codes', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'old.docs.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => ['target' => ['type' => 'redirect', 'value' => 'https://docs.test'], 'code' => 301],
        ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)->toContain('old.docs.test {')
            ->and($content)->toContain('redir https://docs.test{uri} 301');
    });

    it('renders custom redirect routes marked for ACME without Orbit internal TLS paths', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'www.docs.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => [
                'target' => ['type' => 'redirect', 'value' => 'https://docs.test'],
                'code' => 301,
                'tls' => ['managed_by' => 'acme'],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)
            ->toContain("tls {\n        issuer acme\n    }")
            ->toContain('redir https://docs.test{uri} 301')
            ->not->toContain('/etc/orbit/certs/www.docs.test.crt');
    });

    it('accepts numeric string redirect codes in the 3xx range', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'old.docs.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => ['target' => ['type' => 'redirect', 'value' => 'https://docs.test'], 'code' => '302'],
        ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)->toContain('redir https://docs.test{uri} 302');
    });

    it('rejects non numeric redirect codes', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'old.docs.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => ['target' => ['type' => 'redirect', 'value' => 'https://docs.test'], 'code' => 'abc'],
        ]);

        (new ProxyRouteRenderer)->render($route);
    })->throws(RuntimeException::class, "Proxy route 'old.docs.test' has an invalid redirect code.");

    it('rejects redirect codes outside the 3xx range', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'old.docs.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => ['target' => ['type' => 'redirect', 'value' => 'https://docs.test'], 'code' => 200],
        ]);

        (new ProxyRouteRenderer)->render($route);
    })->throws(RuntimeException::class, "Proxy route 'old.docs.test' has an invalid redirect code.");

    it('renders ingress routes through the router upstream with public ACME TLS and forwarded headers', function (): void {
        $ingress = Node::factory()->create(['name' => 'edge-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $ingress->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => [
                    'node_id' => 12,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    ['node_id' => 42, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80'],
                ],
                'backend_artifacts' => [
                    [
                        'node_id' => 42,
                        'domain' => 'example.com',
                        'bind' => '10.6.0.21',
                        'document_root' => '/home/orbit/sites/example/current/public',
                        'php_socket' => '/home/orbit/.config/orbit/php/example.sock',
                        'source_hash' => str_repeat('a', 64),
                    ],
                ],
                'tls' => [
                    'cert_path' => '/home/orbit/.config/orbit/certs/example.com.crt',
                    'key_path' => '/home/orbit/.config/orbit/certs/example.com.key',
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderIngress($route);

        expect($content)->toBe(<<<'CADDY'
example.com {
    tls {
        issuer acme
    }
    encode gzip

    reverse_proxy http://10.6.0.2:80 {
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {scheme}
    }
}

CADDY);
        expect($content)->not->toContain('/home/orbit/.config/orbit/certs/example.com.crt');
    });

    it('renders router routes with private backend pools and forwarded headers', function (): void {
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'example.com',
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
                    [
                        'node_id' => 42,
                        'node' => 'web-1',
                        'url' => 'http://10.6.0.21:80',
                    ],
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderRouterRoute($route);

        expect($content)->toBe(<<<'CADDY'
http://example.com {
    encode gzip

    reverse_proxy http://10.6.0.21:80 {
        lb_policy first
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {http.request.header.X-Forwarded-Proto}
    }
}

CADDY);
    });

    it('keeps router routes pointed at app-role backend routes instead of app runtime containers', function (): void {
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'example.com',
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
                    [
                        'node_id' => 42,
                        'node' => 'web-1',
                        'url' => 'http://10.6.0.21:8081',
                    ],
                ],
                'backend_artifacts' => [
                    [
                        'node_id' => 42,
                        'domain' => 'example.com',
                        'bind' => '10.6.0.21',
                        'document_root' => '/home/orbit/sites/example/current/public',
                        'runtime_upstream' => 'http://orbit-app-example:8080',
                        'php_socket' => null,
                        'source_hash' => str_repeat('b', 64),
                    ],
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderRouterRoute($route);

        expect($content)->toContain('reverse_proxy http://10.6.0.21:8081')
            ->and($content)->not->toContain('orbit-app-example');
    });

    it('renders websocket service router routes with long lived upgrade settings', function (): void {
        $router = Node::factory()->router()->create(['name' => 'gateway-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'websocket.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => [
                'protocol' => 'websocket',
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    [
                        'node_id' => 42,
                        'node' => 'app-dev-1',
                        'url' => 'https://10.6.0.44:8080',
                    ],
                ],
                'router_backend_tls' => [
                    'trusted_by_gateway_ca' => true,
                    'ca_path' => '/etc/orbit/ca/root.crt',
                ],
                'tls' => [
                    'managed_by' => 'internal',
                    'trusted_by_gateway_ca' => true,
                    'cert_path' => '/etc/orbit/certs/websocket.orbit.crt',
                    'key_path' => '/etc/orbit/certs/websocket.orbit.key',
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderRouterRoute($route);

        expect($content)->toBe(<<<'CADDY'
websocket.orbit {
    tls /etc/orbit/certs/websocket.orbit.crt /etc/orbit/certs/websocket.orbit.key
    reverse_proxy https://10.6.0.44:8080 {
        lb_policy first
        flush_interval -1
        stream_close_delay 5m
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {http.request.header.X-Forwarded-Proto}
        transport http {
            tls_trust_pool file /etc/orbit/ca/root.crt
        }
    }
}

CADDY)
            ->and($content)->not->toContain('encode gzip')
            ->and($content)->not->toContain('request_buffers')
            ->and($content)->not->toContain('response_buffers');
    });

    it('renders app websocket public ingress and router routes with long lived upgrade settings', function (): void {
        $ingress = Node::factory()->ingress()->create(['name' => 'edge-1']);
        $router = Node::factory()->router()->create(['name' => 'gateway-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $ingress->id,
            'app_id' => $app->id,
            'domain' => 'ws.docs.test',
            'owner_type' => 'app-websocket',
            'kind' => 'proxy',
            'config' => [
                'placement' => 'ingress',
                'protocol' => 'websocket',
                'target' => ['type' => 'websocket', 'value' => 'https://websocket.orbit'],
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    [
                        'node_id' => 42,
                        'node' => 'app-dev-1',
                        'url' => 'https://10.6.0.44:8080',
                    ],
                ],
                'router_backend_tls' => [
                    'trusted_by_gateway_ca' => true,
                    'ca_path' => '/etc/orbit/ca/root.crt',
                ],
                'tls' => [
                    'cert_path' => '/home/orbit/.config/orbit/certs/ws.docs.test.crt',
                    'key_path' => '/home/orbit/.config/orbit/certs/ws.docs.test.key',
                ],
            ],
        ]);

        $renderer = new ProxyRouteRenderer;

        expect($renderer->renderIngress($route))->toBe(<<<'CADDY'
ws.docs.test {
    tls {
        issuer acme
    }

    reverse_proxy http://10.6.0.2:80 {
        flush_interval -1
        stream_close_delay 5m
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {scheme}
    }
}

CADDY);

        expect($renderer->renderRouterRoute($route))->toBe(<<<'CADDY'
http://ws.docs.test {
    reverse_proxy https://10.6.0.44:8080 {
        lb_policy first
        flush_interval -1
        stream_close_delay 5m
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {http.request.header.X-Forwarded-Proto}
        transport http {
            tls_trust_pool file /etc/orbit/ca/root.crt
        }
    }
}

CADDY);
    });

    it('renders app analytics public ingress and router routes as tracking-only proxies preserving forwarding identity', function (): void {
        $ingress = Node::factory()->ingress()->create(['name' => 'edge-1']);
        $router = Node::factory()->router()->create(['name' => 'gateway-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $ingress->id,
            'app_id' => $app->id,
            'domain' => 'analytics.docs.test',
            'owner_type' => 'app-analytics',
            'kind' => 'proxy',
            'config' => [
                'placement' => 'ingress',
                'protocol' => 'analytics',
                'target' => ['type' => 'analytics', 'value' => 'https://analytics.orbit'],
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    [
                        'node_id' => 42,
                        'node' => 'analytics-1',
                        'url' => 'http://10.6.0.50:8000',
                    ],
                ],
                'tracking_paths' => ['/js/*', '/api/event'],
                'tls' => [
                    'cert_path' => '/home/orbit/.config/orbit/certs/analytics.docs.test.crt',
                    'key_path' => '/home/orbit/.config/orbit/certs/analytics.docs.test.key',
                ],
            ],
        ]);

        $renderer = new ProxyRouteRenderer;

        expect($renderer->renderIngress($route))->toBe(<<<'CADDY'
analytics.docs.test {
    tls {
        issuer acme
    }
    encode gzip

    @plausible_tracking path /js/* /api/event
    reverse_proxy @plausible_tracking http://10.6.0.2:80 {
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {scheme}
        header_up X-Forwarded-For {remote_host}
    }
    respond 404
}

CADDY);

        expect($renderer->renderRouterRoute($route))->toBe(<<<'CADDY'
http://analytics.docs.test {
    encode gzip

    @plausible_tracking path /js/* /api/event
    reverse_proxy @plausible_tracking http://10.6.0.50:8000 {
        lb_policy first
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {http.request.header.X-Forwarded-Proto}
        header_up X-Forwarded-For {http.request.header.X-Forwarded-For}
    }
    respond 404
}

CADDY);
    });

    it('renders the private analytics service route without tracking-only path restrictions', function (): void {
        $router = Node::factory()->router()->create(['name' => 'gateway-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'analytics.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => [
                'protocol' => 'analytics',
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    [
                        'node_id' => 42,
                        'node' => 'analytics-1',
                        'url' => 'http://10.6.0.50:8000',
                    ],
                ],
                'tls' => [
                    'managed_by' => 'internal',
                    'trusted_by_gateway_ca' => true,
                    'cert_path' => '/etc/orbit/certs/analytics.orbit.crt',
                    'key_path' => '/etc/orbit/certs/analytics.orbit.key',
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderRouterRoute($route);

        expect($content)->toContain('analytics.orbit {')
            ->toContain('reverse_proxy http://10.6.0.50:8000')
            ->toContain('header_up X-Forwarded-For {http.request.header.X-Forwarded-For}')
            ->not->toContain('@plausible_tracking')
            ->not->toContain('respond 404');
    });

    it('rejects router routes whose upstream host is not a valid IP address', function (): void {
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'url' => 'http://gateway:80',
                ],
                'router_backend_pool' => [
                    [
                        'node_id' => 42,
                        'node' => 'web-1',
                        'url' => 'http://10.6.0.21:80',
                    ],
                ],
            ],
        ]);

        (new ProxyRouteRenderer)->renderRouterRoute($route);
    })->throws(RuntimeException::class, "Proxy route 'example.com' backend artifact has an invalid bind address.");

    it('rejects ingress routes with invalid router upstream urls', function (): void {
        $ingress = Node::factory()->create(['name' => 'edge-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $ingress->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => [
                    'node_id' => 12,
                    'node' => 'gateway-1',
                    'url' => "http://10.6.0.2:80\nmalicious",
                ],
                'tls' => [
                    'cert_path' => '/home/orbit/.config/orbit/certs/example.com.crt',
                    'key_path' => '/home/orbit/.config/orbit/certs/example.com.key',
                ],
            ],
        ]);

        (new ProxyRouteRenderer)->renderIngress($route);
    })->throws(RuntimeException::class, 'Proxy route router upstream requires a valid http or https url.');

    it('ignores persisted internal tls paths on public ingress routes', function (): void {
        $ingress = Node::factory()->create(['name' => 'edge-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $ingress->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => [
                    'node_id' => 12,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'tls' => [
                    'cert_path' => "relative/example.com.crt\n",
                    'key_path' => '/home/orbit/.config/orbit/certs/example.com.key',
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderIngress($route);

        expect($content)
            ->toContain("tls {\n        issuer acme\n    }")
            ->not->toContain('relative/example.com.crt')
            ->not->toContain('/home/orbit/.config/orbit/certs/example.com.key');
    });

    it('renders private backend routes for PHP apps as HTTP reverse proxies to the FrankenPHP runtime container', function (): void {
        $appNode = Node::factory()->create(['name' => 'web-1']);
        $app = App::factory()->for($appNode, 'node')->create([
            'name' => 'example',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'app_id' => $app->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [
                    [
                        'node_id' => $appNode->id,
                        'domain' => 'example.com',
                        'bind' => '10.6.0.21',
                        'document_root' => '/home/orbit/sites/example/current/public',
                        'runtime_upstream' => 'http://orbit-app-example:8080',
                        'php_socket' => null,
                        'source_hash' => str_repeat('b', 64),
                    ],
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderPrivateBackend($route, $route->config['backend_artifacts'][0]);

        expect($content)->toBe(<<<'CADDY'
http://example.com:8081 {
    encode gzip

    import security_headers
    import profiling_headers
    import path_blocking_public_root
    import security_txt
    import cache_headers

    reverse_proxy http://orbit-app-example:8080 {
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {http.request.header.X-Forwarded-Proto}
    }
}

CADDY);
    });

    it('renders private backend routes for static apps as file_server only without PHP', function (): void {
        $appNode = Node::factory()->create(['name' => 'web-1']);
        $app = App::factory()->for($appNode, 'node')->static()->create([
            'name' => 'marketing',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'app_id' => $app->id,
            'domain' => 'marketing.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [
                    [
                        'node_id' => $appNode->id,
                        'domain' => 'marketing.test',
                        'bind' => '10.6.0.21',
                        'document_root' => '/home/orbit/sites/marketing/current/public',
                        'runtime_upstream' => null,
                        'php_socket' => null,
                        'source_hash' => str_repeat('b', 64),
                    ],
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderPrivateBackend($route, $route->config['backend_artifacts'][0]);

        expect($content)->toContain('file_server')
            ->and($content)->toContain('root * /home/orbit/sites/marketing/current/public')
            ->and($content)->not->toContain('php_fastcgi')
            ->and($content)->not->toContain('reverse_proxy');
    });

    it('rejects private backend routes with invalid bind addresses', function (): void {
        $appNode = Node::factory()->create(['name' => 'web-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [
                    [
                        'node_id' => $appNode->id,
                        'domain' => 'example.com',
                        'bind' => '10.6.0.21 bad',
                        'document_root' => '/home/orbit/sites/example/current/public',
                        'php_socket' => '/home/orbit/.config/orbit/php/example.sock',
                        'source_hash' => str_repeat('b', 64),
                    ],
                ],
            ],
        ]);

        (new ProxyRouteRenderer)->renderPrivateBackend($route, $route->config['backend_artifacts'][0]);
    })->throws(RuntimeException::class, "Proxy route 'example.com' backend artifact has an invalid bind address.");

    it('rejects static-app private backend routes with unsafe document root paths', function (): void {
        $appNode = Node::factory()->create(['name' => 'web-1']);
        $app = App::factory()->for($appNode, 'node')->static()->create([
            'name' => 'example',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'app_id' => $app->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [
                    [
                        'node_id' => $appNode->id,
                        'domain' => 'example.com',
                        'bind' => '10.6.0.21',
                        'document_root' => "relative/path\n",
                        'runtime_upstream' => null,
                        'php_socket' => null,
                        'source_hash' => str_repeat('b', 64),
                    ],
                ],
            ],
        ]);

        (new ProxyRouteRenderer)->renderPrivateBackend($route, $route->config['backend_artifacts'][0]);
    })->throws(RuntimeException::class, "Proxy route 'example.com' backend artifact has an invalid document root.");

    it('rejects PHP-app private backend routes with unsafe runtime container upstream values', function (): void {
        $appNode = Node::factory()->create(['name' => 'web-1']);
        $app = App::factory()->for($appNode, 'node')->create([
            'name' => 'example',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'app_id' => $app->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [
                    [
                        'node_id' => $appNode->id,
                        'domain' => 'example.com',
                        'bind' => '10.6.0.21',
                        'document_root' => '/home/orbit/sites/example/current/public',
                        'runtime_upstream' => "http://orbit-app-example:8080\r\n",
                        'php_socket' => null,
                        'source_hash' => str_repeat('b', 64),
                    ],
                ],
            ],
        ]);

        (new ProxyRouteRenderer)->renderPrivateBackend($route, $route->config['backend_artifacts'][0]);
    })->throws(RuntimeException::class, "Proxy route 'example.com' has an invalid runtime container upstream.");

    it('derives a FrankenPHP runtime upstream from the app identity for a legacy app route persisted with only php_socket (no runtime_upstream) and never emits php_fastcgi', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->for($node, 'node')->create(['name' => 'legacy-docs']);

        $route = ProxyRoute::factory()
            ->for($node, 'node')
            ->for($app, 'app')
            ->create([
                'domain' => 'legacy-docs.test',
                'owner_type' => 'app',
                'kind' => 'app',
                'config' => [
                    'document_root' => '/home/orbit/apps/legacy-docs/public',
                    // Legacy origin/main config: only php_socket, no runtime_upstream.
                    'php_socket' => '/var/run/php/orbit-legacy-docs.sock',
                    'tls' => [
                        'cert_path' => '/etc/orbit/certs/legacy-docs.test.crt',
                        'key_path' => '/etc/orbit/certs/legacy-docs.test.key',
                    ],
                ],
            ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)->toContain('legacy-docs.test {')
            // Renderer must derive runtime_upstream from the app identity
            // so legacy routes do not throw before ProxyRouteFixer can repair.
            ->and($content)->toContain('reverse_proxy http://orbit-app-legacy-docs:8080')
            // App routes never revert to php_fastcgi under the Docker-first model.
            ->and($content)->not->toContain('php_fastcgi')
            // file_server is reserved for static apps.
            ->and($content)->not->toContain('file_server');
    });

    it('derives a FrankenPHP runtime upstream from the app identity for a legacy private backend artifact (no runtime_upstream)', function (): void {
        $appNode = createTestAppHostNode(['wireguard_address' => '10.6.0.21']);
        $app = App::factory()->for($appNode, 'node')->create(['name' => 'legacy-docs']);

        $route = ProxyRoute::factory()
            ->for($appNode, 'node')
            ->for($app, 'app')
            ->create([
                'domain' => 'legacy-docs.test',
                'owner_type' => 'app',
                'kind' => 'app',
                'config' => [
                    'placement' => 'ingress',
                    'backend_artifacts' => [
                        [
                            'node_id' => $appNode->id,
                            'bind' => '10.6.0.21',
                            'document_root' => '/home/orbit/apps/legacy-docs/public',
                            // Legacy artifact: only php_socket, no runtime_upstream.
                            'php_socket' => '/var/run/php/orbit-legacy-docs.sock',
                        ],
                    ],
                ],
            ]);

        $content = (new ProxyRouteRenderer)->renderPrivateBackend($route, $route->config['backend_artifacts'][0]);

        expect($content)->toContain('reverse_proxy http://orbit-app-legacy-docs:8080')
            ->and($content)->not->toContain('php_fastcgi')
            ->and($content)->not->toContain('file_server');
    });

    it('still renders static app routes with file_server even when the persisted config carries a legacy php_socket', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->for($node, 'node')->static()->create(['name' => 'legacy-marketing']);

        $route = ProxyRoute::factory()
            ->for($node, 'node')
            ->for($app, 'app')
            ->create([
                'domain' => 'legacy-marketing.test',
                'owner_type' => 'app',
                'kind' => 'app',
                'config' => [
                    'document_root' => '/home/orbit/apps/legacy-marketing/public',
                    'php_socket' => '/var/run/php/orbit-legacy-marketing.sock',
                    'tls' => [
                        'cert_path' => '/etc/orbit/certs/legacy-marketing.test.crt',
                        'key_path' => '/etc/orbit/certs/legacy-marketing.test.key',
                    ],
                ],
            ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)->toContain('file_server')
            ->and($content)->not->toContain('php_fastcgi')
            ->and($content)->not->toContain('reverse_proxy');
    });

    it('renders workspace PHP routes as reverse_proxy to the FrankenPHP runtime container', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()
            ->for($node, 'node')
            ->for($app, 'app')
            ->create([
                'domain' => 'feature-a.docs.test',
                'owner_type' => 'workspace',
                'kind' => 'workspace',
                'config' => [
                    'document_root' => '/home/orbit/apps/docs/.worktrees/feature-a/public',
                    'runtime_upstream' => 'http://orbit-ws-docs-feature-a',
                    'php_socket' => null,
                    'tls' => [
                        'cert_path' => '/etc/orbit/certs/feature-a.docs.test.crt',
                        'key_path' => '/etc/orbit/certs/feature-a.docs.test.key',
                    ],
                ],
            ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)->toContain('feature-a.docs.test {')
            ->and($content)->toContain('reverse_proxy http://orbit-ws-docs-feature-a')
            ->and($content)->not->toContain('php_fastcgi')
            ->and($content)->not->toContain('file_server');
    });

    it('derives a FrankenPHP runtime upstream from the workspace identity for a legacy workspace route persisted with only php_socket', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->for($node, 'node')->create(['name' => 'legacy-docs']);
        $workspace = Workspace::factory()->for($app, 'app')->create(['name' => 'feature-a']);

        $route = ProxyRoute::factory()
            ->for($node, 'node')
            ->for($app, 'app')
            ->for($workspace, 'workspace')
            ->create([
                'domain' => 'feature-a.legacy-docs.test',
                'owner_type' => 'workspace',
                'kind' => 'workspace',
                'config' => [
                    'document_root' => '/home/orbit/apps/legacy-docs/.worktrees/feature-a/public',
                    // Legacy origin/main config: only php_socket, no runtime_upstream.
                    'php_socket' => '/var/run/php/orbit-legacy-docs.sock',
                    'tls' => [
                        'cert_path' => '/etc/orbit/certs/feature-a.legacy-docs.test.crt',
                        'key_path' => '/etc/orbit/certs/feature-a.legacy-docs.test.key',
                    ],
                ],
            ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)->toContain('feature-a.legacy-docs.test {')
            ->and($content)->toContain('reverse_proxy http://orbit-ws-legacy-docs-feature-a')
            ->and($content)->not->toContain('php_fastcgi')
            ->and($content)->not->toContain('file_server');
    });

    it('renders private backend routes for PHP workspaces as HTTP reverse proxies to the FrankenPHP runtime container', function (): void {
        $appNode = Node::factory()->create(['name' => 'web-1']);
        $app = App::factory()->for($appNode, 'node')->create([
            'name' => 'example',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'app_id' => $app->id,
            'domain' => 'feature-a.example.com',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [
                    [
                        'node_id' => $appNode->id,
                        'domain' => 'feature-a.example.com',
                        'bind' => '10.6.0.21',
                        'document_root' => '/home/orbit/sites/example/.worktrees/feature-a/public',
                        'runtime_upstream' => 'http://orbit-ws-example-feature-a',
                        'php_socket' => null,
                        'source_hash' => str_repeat('b', 64),
                    ],
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderPrivateBackend($route, $route->config['backend_artifacts'][0]);

        expect($content)->toBe(<<<'CADDY'
http://feature-a.example.com:8081 {
    encode gzip

    import security_headers
    import profiling_headers
    import path_blocking_public_root
    import security_txt
    import cache_headers

    reverse_proxy http://orbit-ws-example-feature-a {
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {http.request.header.X-Forwarded-Proto}
    }
}

CADDY);
    });

    it('renders private backend routes for static workspaces as file_server only', function (): void {
        $appNode = Node::factory()->create(['name' => 'web-1']);
        $app = App::factory()->for($appNode, 'node')->static()->create([
            'name' => 'marketing',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'app_id' => $app->id,
            'domain' => 'feature-a.marketing.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [
                    [
                        'node_id' => $appNode->id,
                        'domain' => 'feature-a.marketing.test',
                        'bind' => '10.6.0.21',
                        'document_root' => '/home/orbit/sites/marketing/.worktrees/feature-a/public',
                        'runtime_upstream' => null,
                        'php_socket' => null,
                        'source_hash' => str_repeat('b', 64),
                    ],
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderPrivateBackend($route, $route->config['backend_artifacts'][0]);

        expect($content)->toContain('file_server')
            ->and($content)->toContain('root * /home/orbit/sites/marketing/.worktrees/feature-a/public')
            ->and($content)->not->toContain('php_fastcgi')
            ->and($content)->not->toContain('reverse_proxy');
    });
});

describe('s3 upload-safe proxy rendering', function (): void {
    it('renders the private s3.orbit service route with upload-safe streaming and preserves Host and X-Forwarded-Proto headers', function (): void {
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 's3.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'seaweedfs',
                'protocol' => 's3',
                'target' => [
                    'type' => 'upstream',
                    'value' => 'http://storage-1.s3.orbit:8333',
                ],
                'upstreams' => [
                    ['scheme' => 'http', 'host' => 'storage-1.s3.orbit', 'port' => 8333],
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)->toBe(<<<'CADDY'
s3.orbit {
    tls /etc/orbit/certs/s3.orbit.crt /etc/orbit/certs/s3.orbit.key
    reverse_proxy http://storage-1.s3.orbit:8333 {
        flush_interval -1
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {scheme}
    }
}

CADDY)
            ->and($content)->not->toContain('encode gzip')
            ->and($content)->not->toContain('request_buffers')
            ->and($content)->not->toContain('stream_close_delay');
    });

    it('renders the public s3 ingress route with upload-safe streaming and preserves Host and X-Forwarded-Proto headers', function (): void {
        $ingress = Node::factory()->ingress()->create(['name' => 'edge-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $ingress->id,
            'domain' => 's3.example.com',
            'owner_type' => 's3',
            'kind' => 'proxy',
            'config' => [
                'placement' => 'ingress',
                'owner_name' => 'seaweedfs',
                'protocol' => 's3',
                'target' => [
                    'type' => 'upstream',
                    'value' => 'https://s3.orbit',
                ],
                'router_upstream' => [
                    'node_id' => 12,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.1:80',
                ],
                'tls' => [
                    'cert_path' => '/etc/orbit/certs/s3.example.com.crt',
                    'key_path' => '/etc/orbit/certs/s3.example.com.key',
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderIngress($route);

        expect($content)->toBe(<<<'CADDY'
s3.example.com {
    tls {
        issuer acme
    }

    reverse_proxy http://10.6.0.1:80 {
        flush_interval -1
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {scheme}
    }
}

CADDY)
            ->and($content)->not->toContain('encode gzip')
            ->and($content)->not->toContain('request_buffers')
            ->and($content)->not->toContain('stream_close_delay');
    });

    it('s3 service route source hash is stable and reflects upload-safe streaming directives', function (): void {
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 's3.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'seaweedfs',
                'protocol' => 's3',
                'target' => ['type' => 'upstream', 'value' => 'http://storage-1.s3.orbit:8333'],
                'upstreams' => [
                    ['scheme' => 'http', 'host' => 'storage-1.s3.orbit', 'port' => 8333],
                ],
            ],
        ]);

        $renderer = new ProxyRouteRenderer;
        $content = $renderer->render($route);
        $hash = $renderer->sourceHash($route);

        expect($hash)->toBe(hash('sha256', $content))
            ->and($content)->toContain('flush_interval -1');
    });
});
