<?php

declare(strict_types=1);

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Apps\AppProxyRouteRuntimeUpstreamBackfill;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Runtime\OrbitCaddyContainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Caddyfile that origin/main would have rendered for a development-environment
 * app route using `php_fastcgi` against a unix socket. Used to construct a
 * pre-migration `source_hash` so we can prove the backfill RECOMPUTES it to
 * match the Docker-first reverse_proxy rendering — otherwise an observed
 * legacy php_fastcgi Caddy file would still be reported healthy by
 * ProxyRouteProbe.
 */
function legacyPhpFastCgiAppCaddyfile(string $domain, string $documentRoot, string $phpSocket, string $certPath, string $keyPath): string
{
    return <<<CADDY
{$domain} {
    tls {$certPath} {$keyPath}
    root * {$documentRoot}
    encode gzip

    import security_headers
    import profiling_headers
    import path_blocking_public_root
    import security_txt
    import cache_headers
    php_fastcgi unix/{$phpSocket}
    file_server
}

CADDY;
}

function legacyPhpFastCgiPrivateBackendCaddyfile(int $port, string $domain, string $documentRoot, string $phpSocket): string
{
    return <<<CADDY
http://{$domain}:{$port} {
    root * {$documentRoot}
    encode gzip

    import security_headers
    import profiling_headers
    import path_blocking_public_root
    import security_txt
    import cache_headers
    php_fastcgi unix/{$phpSocket}
    file_server
}

CADDY;
}

/**
 * Verify the Docker-first runtime migration's backfill behavior against the
 * AppProxyRouteRuntimeUpstreamBackfill service that backs it. Without this,
 * routes persisted by origin/main contain only a `php_socket` and the
 * post-todo-315 renderer would throw before ProxyRouteFixer / doctor restore
 * could repair them.
 */
it('backfills legacy app proxy route configs with a Docker-first runtime_upstream derived from the app identity and clears the legacy php_socket', function (): void {
    $node = Node::factory()->appDev()->create();
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'legacy-docs',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $id = DB::table('proxy_routes')->insertGetId([
        'node_id' => $node->id,
        'app_id' => $app->id,
        'owner_type' => 'app',
        'kind' => 'app',
        'domain' => 'legacy-docs.test',
        'source_hash' => str_repeat('0', 64),
        'config' => json_encode([
            'document_root' => '/home/orbit/apps/legacy-docs/public',
            'php_socket' => '/var/run/php/orbit-legacy-docs.sock',
            'tls' => [
                'cert_path' => '/etc/orbit/certs/legacy-docs.test.crt',
                'key_path' => '/etc/orbit/certs/legacy-docs.test.key',
            ],
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new AppProxyRouteRuntimeUpstreamBackfill)->run();

    $row = DB::table('proxy_routes')->find($id);
    $config = json_decode((string) $row->config, true);

    expect($config['runtime_upstream'] ?? null)->toBe('http://orbit-app-legacy-docs:8080')
        ->and(array_key_exists('php_socket', $config))->toBeTrue()
        ->and($config['php_socket'])->toBeNull()
        ->and($config['document_root'])->toBe('/home/orbit/apps/legacy-docs/public')
        ->and($config['tls']['cert_path'])->toBe('/etc/orbit/certs/legacy-docs.test.crt');
});

it('backfills nested backend_artifacts entries too (ingress topology with private backends)', function (): void {
    $edge = Node::factory()->ingress()->create();
    $appNode = Node::factory()->appDev()->create();
    $app = App::factory()->for($appNode, 'node')->create([
        'name' => 'legacy-docs',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $id = DB::table('proxy_routes')->insertGetId([
        'node_id' => $edge->id,
        'app_id' => $app->id,
        'owner_type' => 'app',
        'kind' => 'app',
        'domain' => 'legacy-docs.test',
        'source_hash' => str_repeat('0', 64),
        'config' => json_encode([
            'placement' => 'ingress',
            'document_root' => '/home/orbit/apps/legacy-docs/public',
            'php_socket' => '/var/run/php/orbit-legacy-docs.sock',
            'backend_artifacts' => [
                [
                    'node_id' => $appNode->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/legacy-docs/public',
                    'php_socket' => '/var/run/php/orbit-legacy-docs.sock',
                ],
            ],
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new AppProxyRouteRuntimeUpstreamBackfill)->run();

    $row = DB::table('proxy_routes')->find($id);
    $config = json_decode((string) $row->config, true);

    expect($config['runtime_upstream'] ?? null)->toBe('http://orbit-app-legacy-docs:8080')
        ->and($config['php_socket'])->toBeNull()
        ->and($config['backend_artifacts'][0]['runtime_upstream'] ?? null)->toBe('http://orbit-app-legacy-docs:8080')
        ->and($config['backend_artifacts'][0]['php_socket'])->toBeNull();
});

it('does not backfill static app routes (they have no runtime_upstream)', function (): void {
    $node = Node::factory()->appDev()->create();
    $app = App::factory()->for($node, 'node')->static()->create([
        'name' => 'legacy-marketing',
    ]);

    $id = DB::table('proxy_routes')->insertGetId([
        'node_id' => $node->id,
        'app_id' => $app->id,
        'owner_type' => 'app',
        'kind' => 'app',
        'domain' => 'legacy-marketing.test',
        'source_hash' => str_repeat('0', 64),
        'config' => json_encode([
            'document_root' => '/home/orbit/apps/legacy-marketing/public',
            'php_socket' => '/var/run/php/orbit-legacy-marketing.sock',
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new AppProxyRouteRuntimeUpstreamBackfill)->run();

    $row = DB::table('proxy_routes')->find($id);
    $config = json_decode((string) $row->config, true);

    expect(array_key_exists('runtime_upstream', $config))->toBeFalse()
        ->and($config['document_root'])->toBe('/home/orbit/apps/legacy-marketing/public');
});

it('leaves non-app proxy routes untouched (kind=proxy, kind=redirect)', function (): void {
    $node = Node::factory()->appDev()->create();

    $id = DB::table('proxy_routes')->insertGetId([
        'node_id' => $node->id,
        'app_id' => null,
        'owner_type' => 'custom',
        'kind' => 'proxy',
        'domain' => 'metrics.test',
        'source_hash' => str_repeat('0', 64),
        'config' => json_encode([
            'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:9090'],
            'upstream' => 'http://127.0.0.1:9090',
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new AppProxyRouteRuntimeUpstreamBackfill)->run();

    $row = DB::table('proxy_routes')->find($id);
    $config = json_decode((string) $row->config, true);

    expect(array_key_exists('runtime_upstream', $config))->toBeFalse()
        ->and($config['upstream'])->toBe('http://127.0.0.1:9090');
});

it('is idempotent: re-running over already-backfilled rows does not mutate them', function (): void {
    $node = Node::factory()->appDev()->create();
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'legacy-docs',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $id = DB::table('proxy_routes')->insertGetId([
        'node_id' => $node->id,
        'app_id' => $app->id,
        'owner_type' => 'app',
        'kind' => 'app',
        'domain' => 'legacy-docs.test',
        'source_hash' => str_repeat('0', 64),
        'config' => json_encode([
            'document_root' => '/home/orbit/apps/legacy-docs/public',
            'runtime_upstream' => 'http://orbit-app-legacy-docs:8080',
            'php_socket' => null,
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new AppProxyRouteRuntimeUpstreamBackfill)->run();
    (new AppProxyRouteRuntimeUpstreamBackfill)->run();

    $row = DB::table('proxy_routes')->find($id);
    $config = json_decode((string) $row->config, true);

    expect($config['runtime_upstream'])->toBe('http://orbit-app-legacy-docs:8080')
        ->and($config['php_socket'])->toBeNull();
});

it('updates non-ingress source_hash from the legacy php_fastcgi rendered content hash to the Docker-first reverse_proxy rendered content hash', function (): void {
    $node = Node::factory()->appDev()->create();
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'legacy-docs',
        'document_root' => 'public',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $documentRoot = '/home/orbit/apps/legacy-docs/public';
    $phpSocket = '/var/run/php/orbit-legacy-docs.sock';
    $certPath = '/etc/orbit/certs/legacy-docs.test.crt';
    $keyPath = '/etc/orbit/certs/legacy-docs.test.key';

    $legacyContent = legacyPhpFastCgiAppCaddyfile(
        domain: 'legacy-docs.test',
        documentRoot: $documentRoot,
        phpSocket: $phpSocket,
        certPath: $certPath,
        keyPath: $keyPath,
    );
    $legacyHash = hash('sha256', $legacyContent);

    $id = DB::table('proxy_routes')->insertGetId([
        'node_id' => $node->id,
        'app_id' => $app->id,
        'owner_type' => 'app',
        'kind' => 'app',
        'domain' => 'legacy-docs.test',
        'source_hash' => $legacyHash,
        'config' => json_encode([
            'document_root' => $documentRoot,
            'php_socket' => $phpSocket,
            'tls' => [
                'cert_path' => $certPath,
                'key_path' => $keyPath,
            ],
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Sanity: pre-backfill, the persisted source_hash equals the hash of the
    // legacy php_fastcgi Caddyfile — a probe against an observed legacy file
    // on disk with the same hash would be reported healthy.
    expect(DB::table('proxy_routes')->find($id)->source_hash)->toBe($legacyHash);

    (new AppProxyRouteRuntimeUpstreamBackfill)->run();

    $route = ProxyRoute::query()->where('id', $id)->with('app')->firstOrFail();
    $dockerFirstContent = (new ProxyRouteRenderer)->render($route);
    $dockerFirstHash = hash('sha256', $dockerFirstContent);

    expect($route->source_hash)->toBe($dockerFirstHash)
        ->and($route->source_hash)->not->toBe($legacyHash)
        ->and($dockerFirstContent)->toContain('reverse_proxy http://orbit-app-legacy-docs:8080')
        ->and($dockerFirstContent)->not->toContain('php_fastcgi')
        ->and($dockerFirstContent)->not->toContain($phpSocket);
});

it('updates each ingress backend_artifact source_hash from the legacy private-backend php_fastcgi hash to the Docker-first reverse_proxy hash', function (): void {
    $edge = Node::factory()->ingress()->create(['wireguard_address' => '10.6.0.4']);
    $appNode = Node::factory()->appDev()->create(['wireguard_address' => '10.6.0.21']);
    $app = App::factory()->for($appNode, 'node')->create([
        'name' => 'legacy-docs',
        'document_root' => 'public',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $documentRoot = '/home/orbit/apps/legacy-docs/public';
    $phpSocket = '/var/run/php/orbit-legacy-docs.sock';

    // Render via the historical private-backend php_fastcgi format so we can
    // anchor `backend_artifacts[*].source_hash` to a concrete legacy value.
    $port = OrbitCaddyContainer::PrivateBackendPort;
    $legacyBackendContent = legacyPhpFastCgiPrivateBackendCaddyfile(
        port: $port,
        domain: 'legacy-docs.test',
        documentRoot: $documentRoot,
        phpSocket: $phpSocket,
    );
    $legacyBackendHash = hash('sha256', $legacyBackendContent);

    $id = DB::table('proxy_routes')->insertGetId([
        'node_id' => $edge->id,
        'app_id' => $app->id,
        'owner_type' => 'app',
        'kind' => 'app',
        'domain' => 'legacy-docs.test',
        'source_hash' => str_repeat('e', 64),
        'config' => json_encode([
            'placement' => 'ingress',
            'backend_artifacts' => [
                [
                    'node_id' => $appNode->id,
                    'bind' => '10.6.0.21',
                    'document_root' => $documentRoot,
                    'php_socket' => $phpSocket,
                    'source_hash' => $legacyBackendHash,
                ],
            ],
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Sanity: pre-backfill, the persisted backend artifact source_hash equals
    // the legacy php_fastcgi private-backend content hash.
    $preConfig = json_decode((string) DB::table('proxy_routes')->find($id)->config, true);
    expect($preConfig['backend_artifacts'][0]['source_hash'])->toBe($legacyBackendHash);

    (new AppProxyRouteRuntimeUpstreamBackfill)->run();

    $route = ProxyRoute::query()->where('id', $id)->with('app')->firstOrFail();
    $config = $route->config;
    $artifact = $config['backend_artifacts'][0];

    $dockerFirstBackendContent = (new ProxyRouteRenderer)->renderPrivateBackend($route, $artifact);
    $dockerFirstBackendHash = hash('sha256', $dockerFirstBackendContent);

    expect($artifact['runtime_upstream'])->toBe('http://orbit-app-legacy-docs:8080')
        ->and($artifact['php_socket'])->toBeNull()
        ->and($artifact['source_hash'])->toBe($dockerFirstBackendHash)
        ->and($artifact['source_hash'])->not->toBe($legacyBackendHash)
        ->and($dockerFirstBackendContent)->toContain('reverse_proxy http://orbit-app-legacy-docs:8080')
        ->and($dockerFirstBackendContent)->not->toContain('php_fastcgi')
        ->and($dockerFirstBackendContent)->not->toContain($phpSocket);
});

it('leaves a non-ingress source_hash that already matches the Docker-first rendering unchanged on a second run (idempotent at hash level)', function (): void {
    $node = Node::factory()->appDev()->create();
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'legacy-docs',
        'document_root' => 'public',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $id = DB::table('proxy_routes')->insertGetId([
        'node_id' => $node->id,
        'app_id' => $app->id,
        'owner_type' => 'app',
        'kind' => 'app',
        'domain' => 'legacy-docs.test',
        'source_hash' => str_repeat('0', 64),
        'config' => json_encode([
            'document_root' => '/home/orbit/apps/legacy-docs/public',
            'php_socket' => '/var/run/php/orbit-legacy-docs.sock',
            'tls' => [
                'cert_path' => '/etc/orbit/certs/legacy-docs.test.crt',
                'key_path' => '/etc/orbit/certs/legacy-docs.test.key',
            ],
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new AppProxyRouteRuntimeUpstreamBackfill)->run();
    $afterFirst = DB::table('proxy_routes')->find($id)->source_hash;

    (new AppProxyRouteRuntimeUpstreamBackfill)->run();
    $afterSecond = DB::table('proxy_routes')->find($id)->source_hash;

    $route = ProxyRoute::query()->where('id', $id)->with('app')->firstOrFail();
    $dockerFirstHash = hash('sha256', (new ProxyRouteRenderer)->render($route));

    expect($afterFirst)->toBe($dockerFirstHash)
        ->and($afterSecond)->toBe($dockerFirstHash)
        ->and($afterFirst)->toBe($afterSecond);
});
