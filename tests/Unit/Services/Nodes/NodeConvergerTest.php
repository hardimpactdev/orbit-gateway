<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeConvergenceContext;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\NodeConverger;
use App\Services\Runtime\OrbitCaddyContainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bindDevelopmentDnsMappingTestDoubles('node-converger-dns');
});

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

describe('NodeConverger', function (): void {
    it('applies app-dev setup role baseline and tools before activation', function (): void {
        $node = createTestAppHostNode([
            'name' => 'app-dev-1',
            'status' => NodeStatus::Provisioning,
            'wireguard_address' => '10.6.0.50',
        ]);
        $shell = new NodeConvergerSetupRemoteShell;

        app()->instance(RemoteShell::class, $shell);

        $result = app(NodeConverger::class)->converge(
            node: $node,
            context: NodeConvergenceContext::Setup,
            families: ['node', 'tool'],
        );

        expect($result->successful())->toBeTrue()
            ->and($result->remainingIssues())->toBe([])
            ->and(collect($result->actions())->pluck('family')->unique()->values()->all())->toBe(['node', 'tool'])
            ->and(collect($result->actions())->pluck('mode')->unique()->values()->all())->toBe(['setup'])
            ->and(collect($result->actions())->pluck('details.tool')->filter()->sort()->values()->all())->toBe([
                'caddy',
                'composer',
                'gh',
                'laravel-installer',
                'php-cli',
            ])
            ->and(collect($result->actions())->pluck('details.tool')->filter()->contains('redis'))->toBeFalse()
            ->and(File::exists(app(DevelopmentDnsMappingEnactor::class)->configDir().'/test.conf'))->toBeTrue()
            ->and(NodeTool::query()
                ->where('node_id', $node->id)
                ->whereIn('name', ['caddy', 'composer', 'gh', 'laravel-installer', 'php-cli'])
                ->pluck('name')
                ->sort()
                ->values()
                ->all())->toBe([
                    'caddy',
                    'composer',
                    'gh',
                    'laravel-installer',
                    'php-cli',
                ])
            ->and(implode("\n", $shell->scripts))->not->toContain('doctor --restore')
            ->and(implode("\n", $shell->scripts))->not->toContain(' orbit doctor ')
            ->and($shell->probeScripts())->toHaveCount(2)
            ->and($shell->repairScripts())->toHaveCount(5);
    });

    it('keeps setup drift visible when repair fails', function (): void {
        $node = createTestAppHostNode([
            'name' => 'app-dev-1',
            'status' => NodeStatus::Provisioning,
            'wireguard_address' => '10.6.0.50',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'php-cli',
            'expected_state' => 'installed',
        ]);
        $shell = new NodeConvergerFailingRemoteShell;

        app()->instance(RemoteShell::class, $shell);

        $result = app(NodeConverger::class)->converge(
            node: $node,
            context: NodeConvergenceContext::Setup,
            families: ['tool'],
        );

        expect($result->successful())->toBeFalse()
            ->and($result->actions()[0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-dev-1',
                'key' => 'tool.capability_missing',
                'mode' => 'setup',
                'status' => 'failed',
            ])
            ->and($result->remainingIssues()[0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-dev-1',
                'key' => 'tool.capability_missing',
            ]);
    });
});

function createNodeConvergerAppDevToolRows(Node $node): void
{
    $container = OrbitCaddyContainer::forPrivateNode((string) $node->wireguard_address);

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'caddy',
        'expected_state' => 'installed',
        'config' => ['container' => $container->spec()],
    ]);

    foreach (['php-cli', 'composer', 'gh', 'laravel-installer'] as $tool) {
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => $tool,
            'expected_state' => 'installed',
        ]);
    }
}

final class NodeConvergerSetupRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var array<string, bool> */
    private array $installed = [
        'caddy' => false,
        'php-cli' => false,
        'composer' => false,
        'gh' => false,
        'laravel-installer' => false,
    ];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        if ($this->isProbeScript($script)) {
            return $this->probeResult($node, $options);
        }

        if (($tool = $this->toolForRepairScript($script)) !== null) {
            $this->installed[$tool] = true;
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }

    /**
     * @return list<string>
     */
    public function repairScripts(): array
    {
        return array_values(array_filter(
            $this->scripts,
            fn (string $script): bool => ! $this->isProbeScript($script),
        ));
    }

    /**
     * @return list<string>
     */
    public function probeScripts(): array
    {
        return array_values(array_filter(
            $this->scripts,
            $this->isProbeScript(...),
        ));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function probeResult(Node $node, array $options): RemoteShellResult
    {
        $payload = json_decode((string) ($options['input'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);

        if (is_array($payload['tools'] ?? null)) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: $this->batchProbeOutput($node, $payload['tools']),
                stderr: '',
                durationMs: 1,
            );
        }

        $binary = is_string($payload['binary'] ?? null) ? $payload['binary'] : '';
        $container = is_string($payload['container'] ?? null) ? $payload['container'] : '';

        if ($container === 'orbit-caddy') {
            $hash = OrbitCaddyContainer::forPrivateNode((string) $node->wireguard_address)->specHash();

            return $this->installed['caddy']
                ? new RemoteShellResult(exitCode: 0, stdout: "/usr/bin/docker\tDocker version 27.0.0\trunning\t\t\t\t\t1\trunning\t{$hash}\n", stderr: '', durationMs: 1)
                : new RemoteShellResult(exitCode: 0, stdout: "/usr/bin/docker\tDocker version 27.0.0\tmissing\t\t\t\t\t0\tmissing\t\n", stderr: '', durationMs: 1);
        }

        return match ($binary) {
            '/opt/orbit/php/8.5/bin/php' => $this->installedProbe('php-cli', "/opt/orbit/php/8.5/bin/php\t8.5.6\n"),
            '/usr/local/bin/composer' => $this->installedProbe('composer', "/usr/local/bin/composer\tComposer version 2.9.0\n"),
            'gh' => $this->installedProbe('gh', "/usr/bin/gh\tgh version 2.60.0\n"),
            '/usr/local/bin/laravel' => $this->installedProbe('laravel-installer', "/usr/local/bin/laravel\tLaravel Installer 5.0.0\n"),
            default => new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        };
    }

    /**
     * @param  array<string, mixed>  $tools
     */
    private function batchProbeOutput(Node $node, array $tools): string
    {
        $lines = [];

        foreach ($tools as $name => $tool) {
            if (! is_string($name) || ! is_array($tool)) {
                continue;
            }

            $lines[] = json_encode($this->batchProbePayload($node, $name, $tool), JSON_THROW_ON_ERROR);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function batchProbePayload(Node $node, string $name, array $tool): array
    {
        if ($name === 'caddy') {
            $hash = OrbitCaddyContainer::forPrivateNode((string) $node->wireguard_address)->specHash();

            return [
                'name' => 'caddy',
                'installed' => true,
                'path' => '/usr/bin/docker',
                'version' => 'Docker version 27.0.0',
                'state' => $this->installed['caddy'] ? 'running' : 'missing',
                'config_exists' => null,
                'config_hash' => null,
                'secret_exists' => null,
                'secret_hash' => null,
                'container_exists' => $this->installed['caddy'],
                'container_state' => $this->installed['caddy'] ? 'running' : 'missing',
                'container_spec_hash' => $this->installed['caddy'] ? $hash : null,
            ];
        }

        $installedPayloads = [
            'composer' => ['/usr/local/bin/composer', 'Composer version 2.9.0'],
            'gh' => ['/usr/bin/gh', 'gh version 2.60.0'],
            'laravel-installer' => ['/usr/local/bin/laravel', 'Laravel Installer 5.0.0'],
            'php-cli' => ['/opt/orbit/php/8.5/bin/php', '8.5.6'],
        ];
        [$path, $version] = $installedPayloads[$name] ?? [is_string($tool['binary'] ?? null) ? $tool['binary'] : null, null];
        $installed = $this->installed[$name] ?? false;

        return [
            'name' => $name,
            'installed' => $installed,
            'path' => $installed ? $path : null,
            'version' => $installed ? $version : null,
            'state' => $installed ? 'unknown' : null,
            'config_exists' => null,
            'config_hash' => null,
            'secret_exists' => null,
            'secret_hash' => null,
            'container_exists' => null,
            'container_state' => null,
            'container_spec_hash' => null,
        ];
    }

    private function installedProbe(string $tool, string $stdout): RemoteShellResult
    {
        return $this->installed[$tool]
            ? new RemoteShellResult(exitCode: 0, stdout: $stdout, stderr: '', durationMs: 1)
            : new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1);
    }

    private function isProbeScript(string $script): bool
    {
        return str_contains($script, '# orbit-tool-probe:capability');
    }

    private function toolForRepairScript(string $script): ?string
    {
        return match (true) {
            str_contains($script, 'orbit.caddy.spec_hash') => 'caddy',
            str_contains($script, '# orbit install php-cli') => 'php-cli',
            str_contains($script, '# orbit install composer') => 'composer',
            str_contains($script, '# orbit install gh') => 'gh',
            str_contains($script, '# orbit install laravel-installer') => 'laravel-installer',
            default => null,
        };
    }
}

final class NodeConvergerFailingRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        if (str_contains($script, '# orbit-tool-probe:capability')) {
            return new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1);
        }

        throw new RuntimeException('install failed');
    }
}
