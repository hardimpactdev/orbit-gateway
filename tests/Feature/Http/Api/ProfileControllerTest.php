<?php

declare(strict_types=1);

use App\Contracts\RequestProfiler;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PROFILE_CALLER_WG_IP = '10.6.0.97';

function createProfileCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => PROFILE_CALLER_WG_IP,
        'wireguard_address' => PROFILE_CALLER_WG_IP,
    ], $overrides));
}

function grantProfileAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assignProfileAppHostRole(Node $node, string $role = 'app-dev', array $settings = ['tld' => 'test']): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

describe('ProfileController', function (): void {
    it('resolves a visible app for caller-side profiling without running the gateway profiler', function (): void {
        $caller = createProfileCallerNode();
        $node = Node::factory()->create(['name' => 'app-1']);
        assignProfileAppHostRole($node);
        grantProfileAccess($caller, $node);

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'domain' => 'docs.example.com',
        ]);

        app()->instance(RequestProfiler::class, new class implements RequestProfiler
        {
            public function profile(string $url, array $headers = []): array
            {
                throw new RuntimeException('Gateway profiler must not run during profile resolution.');
            }
        });

        $response = $this->call('GET', '/api/profile/resolve', [
            'target' => 'docs',
            'uri' => '/login',
            'auth_mode' => 'user',
            'user' => '42',
        ], [], [], ['REMOTE_ADDR' => PROFILE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.auth_mode', 'user')
            ->assertJsonPath('success.data.target.app', 'docs')
            ->assertJsonPath('success.data.target.node', 'app-1')
            ->assertJsonPath('success.data.target.domain', 'docs.example.com')
            ->assertJsonPath('success.data.request.method', 'GET')
            ->assertJsonPath('success.data.request.url', 'https://docs.example.com/login')
            ->assertJsonPath('success.data.request.uri', '/login');
    });

    it('profiles a visible app from the gateway origin', function (): void {
        $caller = createProfileCallerNode();
        $node = Node::factory()->create(['name' => 'app-1']);
        assignProfileAppHostRole($node);
        grantProfileAccess($caller, $node);

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'domain' => 'docs.example.com',
        ]);

        $profiler = new class implements RequestProfiler
        {
            public array $calls = [];

            public function profile(string $url, array $headers = []): array
            {
                $this->calls[] = compact('url', 'headers');

                return [
                    'request' => [
                        'method' => 'GET',
                        'url' => $url,
                        'uri' => '/login',
                        'status' => 200,
                        'bytes' => 1234,
                        'completed' => true,
                    ],
                    'timings' => [
                        'dns_ms' => 1.0,
                        'connect_ms' => 2.0,
                        'tls_ms' => 3.0,
                        'ttfb_ms' => 4.0,
                        'download_ms' => 1.5,
                        'total_ms' => 5.5,
                    ],
                    'error' => null,
                    'response_headers' => [],
                ];
            }
        };

        app()->instance(RequestProfiler::class, $profiler);

        $response = $this->call('GET', '/api/profile', [
            'target' => 'docs',
            'uri' => '/login',
            'auth_mode' => 'user',
            'user' => '42',
        ], [], [], ['REMOTE_ADDR' => PROFILE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.origin', 'gateway')
            ->assertJsonPath('success.data.target.app', 'docs')
            ->assertJsonPath('success.data.target.node', 'app-1')
            ->assertJsonPath('success.data.target.domain', 'docs.example.com')
            ->assertJsonPath('success.data.request.url', 'https://docs.example.com/login')
            ->assertJsonPath('success.data.timings.total_ms', 5.5);

        expect($profiler->calls)->toHaveCount(1)
            ->and($profiler->calls[0]['url'])->toBe('https://docs.example.com/login')
            ->and($profiler->calls[0]['headers']['X-TOOLBAR-AUTH'])->toBe('user')
            ->and($profiler->calls[0]['headers']['X-TOOLBAR-USER'])->toBe('42');
    });

    it('profiles a visible development app by derived local domain', function (): void {
        $caller = createProfileCallerNode();
        $node = Node::factory()->appDev(['tld' => 'test'])->create(['name' => 'beast']);
        grantProfileAccess($caller, $node);

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'domain' => null,
        ]);

        app()->instance(RequestProfiler::class, new class implements RequestProfiler
        {
            public array $calls = [];

            public function profile(string $url, array $headers = []): array
            {
                $this->calls[] = compact('url', 'headers');

                return [
                    'request' => [
                        'method' => 'GET',
                        'url' => $url,
                        'uri' => '/',
                        'status' => 200,
                        'bytes' => 1234,
                        'completed' => true,
                    ],
                    'timings' => [
                        'dns_ms' => 1.0,
                        'connect_ms' => 2.0,
                        'tls_ms' => 3.0,
                        'ttfb_ms' => 4.0,
                        'download_ms' => 1.5,
                        'total_ms' => 5.5,
                    ],
                    'error' => null,
                    'response_headers' => [],
                ];
            }
        });

        $response = $this->call('GET', '/api/profile', [
            'target' => 'docs.test',
        ], [], [], ['REMOTE_ADDR' => PROFILE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.target.app', 'docs')
            ->assertJsonPath('success.data.target.domain', 'docs.test')
            ->assertJsonPath('success.data.request.url', 'https://docs.test/');
    });

    it('rejects hidden apps before profiling', function (): void {
        createProfileCallerNode();
        $node = Node::factory()->appDev()->create();

        App::factory()->create([
            'name' => 'hidden',
            'node_id' => $node->id,
        ]);

        $response = $this->call('GET', '/api/profile', [
            'target' => 'hidden',
        ], [], [], ['REMOTE_ADDR' => PROFILE_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'app.not_found')
            ->assertJsonPath('error.meta.app', 'hidden');
    });

    it('resolves a visible app by absolute path', function (): void {
        $caller = createProfileCallerNode();
        $node = Node::factory()->create(['name' => 'app-1']);
        assignProfileAppHostRole($node, 'app-prod', []);
        grantProfileAccess($caller, $node);
        $appPath = sys_get_temp_dir().'/orbit-profile-api-path-'.bin2hex(random_bytes(4));
        mkdir($appPath.'/subdir', 0777, true);

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'domain' => 'docs.example.com',
            'path' => $appPath,
        ]);

        app()->instance(RequestProfiler::class, new class implements RequestProfiler
        {
            public function profile(string $url, array $headers = []): array
            {
                return [
                    'request' => [
                        'method' => 'GET',
                        'url' => $url,
                        'uri' => '/',
                        'status' => 200,
                        'bytes' => 1234,
                        'completed' => true,
                    ],
                    'timings' => [
                        'dns_ms' => 1.0,
                        'connect_ms' => 2.0,
                        'tls_ms' => 3.0,
                        'ttfb_ms' => 4.0,
                        'download_ms' => 1.5,
                        'total_ms' => 5.5,
                    ],
                    'error' => null,
                    'response_headers' => [],
                ];
            }
        });

        $response = $this->call('GET', '/api/profile', [
            'target' => $appPath.'/subdir',
        ], [], [], ['REMOTE_ADDR' => PROFILE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.target.app', 'docs')
            ->assertJsonPath('success.data.request.url', 'https://docs.example.com/');
    });
});
