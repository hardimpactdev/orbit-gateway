<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\ToolDefinition;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolDefinitionRegistry;
use App\Services\Tools\ToolsFixer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses()->group('doctor', 'fixer');
uses(RefreshDatabase::class);

describe('ToolsFixer', function (): void {
    it('returns null for tool.lifecycle_state_mismatch since runtime state is process-family owned', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.lifecycle_state_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool caddy lifecycle state differs from gateway intent.',
            detail: [
                'tool' => 'caddy',
                'expected_state' => 'installed',
                'observed_state' => 'stopped',
            ],
        ));

        // tool.lifecycle_state_mismatch is not a tool issue code; fixer must return null
        expect($action)->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('skips issue codes without catalog-declared repair commands', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.config_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool caddy managed configuration differs from gateway intent.',
            detail: ['tool' => 'caddy'],
        ));

        expect($action)->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('rewrites managed config when the row contains complete content intent', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => hash('sha256', $content),
                    'content' => $content,
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.config_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool dns managed configuration differs from gateway intent.',
            detail: [
                'tool' => 'dns',
                'path' => '/etc/orbit/dns.conf',
            ],
        ));

        expect($action)->toMatchArray([
            'family' => 'tool',
            'node' => 'app-1',
            'key' => 'tool.config_mismatch',
            'status' => 'completed',
        ])->and($shell->scripts[0])->toContain("sudo install -d -m 0755 '/etc/orbit'")
            ->and($shell->scripts[0])->toContain("base64 -d | sudo tee '/etc/orbit/dns.conf' >/dev/null");
    });

    it('honors managed config mode intent when rewriting managed config', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => hash('sha256', $content),
                    'content' => $content,
                    'mode' => '0640',
                    'directory_mode' => '0750',
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.config_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool dns managed configuration differs from gateway intent.',
            detail: [
                'tool' => 'dns',
                'path' => '/etc/orbit/dns.conf',
            ],
        ));

        expect($action)->not->toBeNull()
            ->and($shell->scripts[0])->toContain("sudo install -d -m 0750 '/etc/orbit'")
            ->and($shell->scripts[0])->toContain("sudo chmod 0640 '/etc/orbit/dns.conf'");
    });

    it('does not repair managed config when content does not match declared hash', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => str_repeat('a', 64),
                    'content' => "address=/test/10.6.0.2\n",
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.config_missing',
            kind: DriftKind::Missing,
            summary: 'Tool dns managed configuration is missing.',
            detail: ['tool' => 'dns'],
        ));

        expect($action)->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('rewrites managed secret material when the row contains complete secret intent', function (): void {
        $secret = 'generated-password';
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => hash('sha256', $secret),
                    'content' => $secret,
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.credentials_missing',
            kind: DriftKind::Missing,
            summary: 'Tool opencode-server managed credential material is missing.',
            detail: [
                'tool' => 'opencode-server',
                'path' => '/home/orbit/.config/opencode-server/password',
            ],
        ));

        expect($action)->toMatchArray([
            'family' => 'tool',
            'node' => 'app-1',
            'key' => 'tool.credentials_missing',
            'status' => 'completed',
        ])->and($shell->scripts[0])->toContain("sudo install -d -m 0700 '/home/orbit/.config/opencode-server'")
            ->and($shell->scripts[0])->toContain("base64 -d | sudo tee '/home/orbit/.config/opencode-server/password' >/dev/null")
            ->and($shell->scripts[0])->toContain("sudo chmod 0600 '/home/orbit/.config/opencode-server/password'");
    });

    it('does not repair managed secret material when content does not match declared hash', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => str_repeat('a', 64),
                    'content' => 'generated-password',
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.credentials_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool opencode-server managed credential material differs from gateway intent.',
            detail: ['tool' => 'opencode-server'],
        ));

        expect($action)->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('installs missing host tools through catalog install script', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'composer',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.capability_missing',
            kind: DriftKind::Missing,
            summary: 'Tool composer is missing on the target node.',
            detail: ['tool' => 'composer'],
        ));

        expect($action)->toMatchArray([
            'family' => 'tool',
            'node' => 'app-1',
            'key' => 'tool.capability_missing',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and($shell->scripts[0])->toContain('composer-setup.php')
            ->and($shell->scripts[0])->toContain('sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer');
    });

    it('passes the node managed user into host tool install scripts', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active', 'user' => 'nckrtl']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'laravel-installer',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.capability_missing',
            kind: DriftKind::Missing,
            summary: 'Tool laravel-installer is missing on the target node.',
            detail: ['tool' => 'laravel-installer'],
        ));

        expect($action)->toMatchArray([
            'family' => 'tool',
            'node' => 'app-1',
            'key' => 'tool.capability_missing',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and($shell->scripts[0])->toContain("MANAGED_USER='nckrtl'")
            ->and($shell->scripts[0])->toContain('sudo -u "${MANAGED_USER}"')
            ->and($shell->scripts[0])->not->toContain("MANAGED_USER='orbit'");
    });

    it('returns null for capability missing when no install script exists', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'viteplus',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.capability_missing',
            kind: DriftKind::Missing,
            summary: 'Tool viteplus is missing on the target node.',
            detail: ['tool' => 'viteplus'],
        ));

        expect($action)->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('does not repair stale service process names as tool rows', function (string $toolName, string $key): void {
        $node = createTestAppHostNode(['name' => 'database-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => $toolName,
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: $key,
            kind: DriftKind::Missing,
            summary: "Tool {$toolName} drift should not be repaired as a tool.",
            detail: ['tool' => $toolName],
        ));

        expect(app(ToolCatalog::class)->supports($toolName))->toBeFalse()
            ->and($action)->toBeNull()
            ->and($shell->scripts)->toBe([]);
    })->with([
        'redis capability' => ['redis', 'tool.capability_missing'],
        'redis container' => ['redis', 'tool.container_missing'],
        'mysql capability' => ['mysql', 'tool.capability_missing'],
        'mysql container' => ['mysql', 'tool.container_missing'],
    ]);

    it('reconciles missing or drifted orbit-caddy containers through the declared container spec', function (string $key): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50');
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'config' => ['container' => $container->spec()],
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: $key,
            kind: $key === 'tool.container_missing' ? DriftKind::Missing : DriftKind::Divergent,
            summary: 'orbit-caddy container drift',
            detail: ['tool' => 'caddy'],
        ));

        expect($action)->toMatchArray([
            'family' => 'tool',
            'node' => 'app-1',
            'key' => $key,
            'status' => 'completed',
        ])->and($shell->scripts[0])->toContain('docker container inspect')
            ->and($shell->scripts[0])->toContain('10.6.0.50:80:80')
            ->and($shell->scripts[0])->toContain('orbit.caddy.spec_hash');
    })->with([
        'missing container' => ['tool.container_missing'],
        'drifted container spec' => ['tool.container_spec_mismatch'],
    ]);
});

describe('agent tool fixes', function (): void {
    it('returns completed when canonical proxy route already exists', function (): void {
        [$node, $tool] = createAgentToolForFixer();
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => toolsFixerAgentRouteSourceHash($node, 'openclaw'),
            'config' => toolsFixerAgentRouteConfig('openclaw'),
        ]);

        $fixer = new ToolsFixer(
            remoteShell: new ToolsFixerRemoteShell,
            catalog: makeToolsFixerAgentToolCatalog(),
            proxyRouteRenderer: new ProxyRouteRenderer,
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_route_missing'));

        expect($result)->not->toBeNull()
            ->and($result['status'])->toBe('completed');
    });

    it('returns null when proxy route is owned by a different tool', function (): void {
        [$node, $tool] = createAgentToolForFixer();
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => ['owner_name' => 'hermes'],
        ]);

        $fixer = new ToolsFixer(
            remoteShell: new ToolsFixerRemoteShell,
            catalog: makeToolsFixerAgentToolCatalog(),
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_route_missing'));

        expect($result)->toBeNull();
    });

    it('returns null when proxy route domain is not tool owned', function (): void {
        [$node, $tool] = createAgentToolForFixer();
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => toolsFixerAgentRouteConfig('openclaw'),
        ]);

        $fixer = new ToolsFixer(
            remoteShell: new ToolsFixerRemoteShell,
            catalog: makeToolsFixerAgentToolCatalog(),
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_route_missing'));

        expect($result)->toBeNull();
    });

    it('creates canonical proxy route when missing', function (): void {
        [$node, $tool] = createAgentToolForFixer();
        $fixer = new ToolsFixer(
            remoteShell: new ToolsFixerRemoteShell,
            catalog: makeToolsFixerAgentToolCatalog(),
            proxyRouteRenderer: new ProxyRouteRenderer,
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_route_missing'));

        $route = ProxyRoute::query()->where('domain', 'openclaw.agent')->first();

        expect($result)->not->toBeNull()
            ->and($result['status'])->toBe('completed')
            ->and($route)->not->toBeNull()
            ->and($route->kind)->toBe('proxy')
            ->and($route->owner_type)->toBe('tool')
            ->and($route->config)->toBe(toolsFixerAgentRouteConfig('openclaw'))
            ->and($route->source_hash)->toBe(toolsFixerAgentRouteSourceHash($node, 'openclaw'));
    });

    it('rewrites malformed same-owner proxy routes to the canonical route shape', function (): void {
        [$node, $tool] = createAgentToolForFixer();
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'upstream',
            'source_hash' => str_repeat('a', 64),
            'config' => ['owner_name' => 'openclaw'],
        ]);
        $fixer = new ToolsFixer(
            remoteShell: new ToolsFixerRemoteShell,
            catalog: makeToolsFixerAgentToolCatalog(),
            proxyRouteRenderer: new ProxyRouteRenderer,
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_route_missing'));

        $route = ProxyRoute::query()->where('domain', 'openclaw.agent')->first();

        expect($result)->not->toBeNull()
            ->and($result['status'])->toBe('completed')
            ->and($route->kind)->toBe('proxy')
            ->and($route->config)->toBe(toolsFixerAgentRouteConfig('openclaw'))
            ->and($route->source_hash)->toBe(toolsFixerAgentRouteSourceHash($node, 'openclaw'));
    });

    it('updates credentials when shell returns valid JSON array', function (): void {
        [, $tool] = createAgentToolForFixer();
        $shell = new ToolsFixerRemoteShell([
            "echo '[\"user\",\"pass\"]'" => new RemoteShellResult(
                exitCode: 0,
                stdout: '["user","pass"]',
                stderr: '',
                durationMs: 1,
            ),
        ]);

        $fixer = new ToolsFixer(
            remoteShell: $shell,
            catalog: makeToolsFixerAgentToolCatalog([
                'credentialsScript' => "echo '[\"user\",\"pass\"]'",
            ]),
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_credentials_missing'));

        expect($result)->not->toBeNull()
            ->and($result['status'])->toBe('completed')
            ->and($tool->fresh()->credentials)->toBe(['fields' => ['user', 'pass']]);
    });

    it('returns null when credential shell output is not a valid non-empty array', function (): void {
        [, $tool] = createAgentToolForFixer();
        $shell = new ToolsFixerRemoteShell([
            'echo invalid' => new RemoteShellResult(
                exitCode: 0,
                stdout: 'not-json',
                stderr: '',
                durationMs: 1,
            ),
        ]);

        $fixer = new ToolsFixer(
            remoteShell: $shell,
            catalog: makeToolsFixerAgentToolCatalog([
                'credentialsScript' => 'echo invalid',
            ]),
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_credentials_missing'));

        expect($result)->toBeNull()
            ->and($tool->fresh()->credentials)->toBeNull();
    });

    it('runs useradd and passwd commands and returns completed', function (): void {
        [, $tool] = createAgentToolForFixer();
        $shell = new ToolsFixerRemoteShell([
            'id -u agent >/dev/null 2>&1 || sudo useradd --create-home --shell /bin/bash agent' => new RemoteShellResult(
                exitCode: 0,
                stdout: '',
                stderr: '',
                durationMs: 1,
            ),
            'sudo passwd -l agent >/dev/null 2>&1 || true' => new RemoteShellResult(
                exitCode: 0,
                stdout: '',
                stderr: '',
                durationMs: 1,
            ),
        ]);

        $fixer = new ToolsFixer(
            remoteShell: $shell,
            catalog: makeToolsFixerAgentToolCatalog(),
        );

        $result = $fixer->fix($tool, agentToolDriftEntry('tool.agent_user_missing'));

        expect($result)->not->toBeNull()
            ->and($result['status'])->toBe('completed')
            ->and($shell->calls)->toHaveCount(2);
    });
});

/**
 * @return array{0: Node, 1: NodeTool}
 */
function createAgentToolForFixer(): array
{
    $node = Node::factory()->create([
        'status' => 'active',
        'tld' => 'agent',
    ]);
    $node->roleAssignments()->create([
        'role' => 'agent',
        'status' => 'active',
        'settings' => ['tld' => 'agent'],
    ]);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'openclaw',
        'expected_state' => 'installed',
    ]);

    return [$node, $tool];
}

function agentToolDriftEntry(string $key): DriftEntry
{
    return new DriftEntry(
        family: 'tool',
        key: $key,
        kind: DriftKind::Missing,
        summary: 'Agent tool drift',
        detail: ['tool' => 'openclaw', 'domain' => 'openclaw.agent'],
    );
}

/**
 * @return array{target: array{type: string, value: string}, upstream: string, owner_name: string}
 */
function toolsFixerAgentRouteConfig(string $tool): array
{
    $upstream = 'http://host.docker.internal:8080';

    return [
        'target' => ['type' => 'upstream', 'value' => $upstream],
        'upstream' => $upstream,
        'owner_name' => $tool,
    ];
}

function toolsFixerAgentRouteSourceHash(Node $node, string $tool): string
{
    return app(ProxyRouteRenderer::class)->sourceHash(new ProxyRoute([
        'node_id' => $node->id,
        'domain' => "{$tool}.agent",
        'kind' => 'proxy',
        'owner_type' => 'tool',
        'config' => toolsFixerAgentRouteConfig($tool),
    ]));
}

function makeToolsFixerAgentToolCatalog(array $overrides = []): ToolCatalog
{
    $definition = new ToolsFixerAgentToolDefinition(
        categoryName: $overrides['category'] ?? 'agent',
        hasCredentialsCapability: $overrides['hasCredentials'] ?? true,
        credentialsScript: $overrides['credentialsScript'] ?? "echo '[\"user\",\"pass\"]'",
    );

    return new ToolCatalog(new ToolDefinitionRegistry([$definition]));
}

final class ToolsFixerRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array{command: string, result: RemoteShellResult}>
     */
    public array $calls = [];

    /**
     * @param  array<string, RemoteShellResult>  $responses
     */
    public function __construct(
        private readonly array $responses = [],
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $result = $this->responses[$script] ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);

        $this->scripts[] = $script;
        $this->calls[] = ['command' => $script, 'result' => $result];

        return $result;
    }
}

final class ToolsFixerAgentToolDefinition implements ToolDefinition
{
    public function __construct(
        private readonly string $slugName = 'openclaw',
        private readonly string $categoryName = 'agent',
        private readonly bool $hasCredentialsCapability = true,
        private readonly ?string $credentialsScript = "echo '[\"user\",\"pass\"]'",
    ) {}

    public function slug(): string
    {
        return $this->slugName;
    }

    public function category(): string
    {
        return $this->categoryName;
    }

    public function capabilities(): array
    {
        if (! $this->hasCredentialsCapability) {
            return [];
        }

        return ['credentials'];
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function credentialsScript(array $context = []): ?string
    {
        return $this->credentialsScript;
    }

    public function reconfigureScript(array $config = []): ?string
    {
        return null;
    }

    public function requiredNodeRole(): ?string
    {
        return null;
    }

    public function installScript(array $config = []): ?string
    {
        return null;
    }

    public function removeScript(array $config = []): ?string
    {
        return null;
    }

    public function updateScript(array $config = []): ?string
    {
        return null;
    }

    public function latestSupportedVersion(): ?string
    {
        return null;
    }

    public function relatedProcess(): ?array
    {
        return null;
    }

    public function probeMetadata(): array
    {
        return [];
    }
}
