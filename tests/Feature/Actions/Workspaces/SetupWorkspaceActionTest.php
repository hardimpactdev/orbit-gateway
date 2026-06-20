<?php

declare(strict_types=1);

use App\Actions\Workspaces\SetupWorkspace;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process as OrbitProcess;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceStep;
use App\Services\Workspaces\EnsureWorkspaceProxyRoute;
use App\Tools\CaddyTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'host' => 'gateway',
            'user' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('node_role')->insert([
        'node_id' => 1,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => json_encode([], JSON_THROW_ON_ERROR),
        'last_error' => null,
        'converged_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('apps')->insert([
        [
            'name' => 'demo',
            'domain' => 'demo.beast',
            'node_id' => 1,
            'path' => '/home/nckrtl/apps/demo',
            'php_version' => '8.5',
            'document_root' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    app()->instance(RemoteShell::class, new SetupWorkspaceActionTestShell);
    app()->instance(SiteCertificateInstaller::class, new SetupWorkspaceActionTestCertificateInstaller);
});

it('sets up a workspace and marks it active', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['action'])->toBe('set_up');
    expect($result['workspace'])->toBe('feature-a');
    expect($result['app'])->toBe('demo');

    $workspace->refresh();
    expect($workspace->lifecycle_status)->toBe(WorkspaceLifecycleStatus::Active);
});

it('does not render PHP-FPM pool config for PHP workspaces in the steady-state path', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    expect(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, '/etc/php/8.5/fpm/pool.d/orbit-demo-feature-a.conf')))->toBeFalse()
        ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, "PHP_FPM_SERVICE='php8.5-fpm'")))->toBeFalse()
        ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, 'sudo systemctl restart')))->toBeFalse();
});

it('enacts the FrankenPHP runtime container for PHP workspaces without FPM', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    $runScript = collect($shell->scripts)
        ->first(fn (string $script): bool => str_contains($script, 'docker run -d')
            && str_contains($script, "'orbit-ws-demo-feature-a'"));

    expect($runScript)
        ->toContain('docker run -d')
        ->and($runScript)->toContain("'orbit-ws-demo-feature-a'")
        ->and($runScript)->toContain("'dunglas/frankenphp:1-php8.5-bookworm'")
        ->and($runScript)->toContain('/etc/orbit/workspaces/demo-feature-a.ini')
        ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, "docker image inspect 'dunglas/frankenphp:1-php8.5-bookworm'")))->toBeTrue()
        ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, '/etc/php/8.5/fpm/pool.d/orbit-demo-feature-a.conf')))->toBeFalse();

    expectWorkspaceFrankenPhpRuntimeProcess($workspace);
});

it('reconciles an existing FrankenPHP workspace runtime process row', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    OrbitProcess::factory()->forOwner($workspace)->create([
        'name' => 'frankenphp-demo-feature-a',
        'command' => 'stale command',
        'restart_policy' => ProcessRestartPolicy::Never,
        'crash_notification' => ProcessCrashNotification::AgentIde,
        'runtime' => ProcessRuntime::Systemd,
        'runtime_config' => [
            'container_name' => 'stale-container',
            'php_ini_path' => '/stale.ini',
        ],
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    expectWorkspaceFrankenPhpRuntimeProcess($workspace);
});

it('registers workspace proxy routes against the FrankenPHP runtime container', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    $siteScript = collect($shell->scripts)
        ->first(fn (string $script): bool => str_contains($script, '/etc/caddy/sites/feature-a.demo.caddy'));
    $caddySite = base64_decode((string) str((string) $siteScript)->match("/printf %s\\s+'([^']+)'/")->toString(), true);
    $route = $workspace->proxyRoutes()->first();

    expect($caddySite)->toContain('tls /home/gateway/.config/orbit/certs/feature-a.demo.crt /home/gateway/.config/orbit/certs/feature-a.demo.key')
        ->and($caddySite)->toContain('reverse_proxy http://orbit-ws-demo-feature-a')
        ->and($caddySite)->not->toContain('php_fastcgi')
        ->and((string) $siteScript)->toContain(CaddyTool::reloadCommand())
        ->and((string) $siteScript)->not->toContain("docker restart 'orbit-caddy'")
        ->and((string) $siteScript)->not->toContain('sudo systemctl reload caddy')
        ->and($route?->config['runtime_upstream'])->toBe('http://orbit-ws-demo-feature-a')
        ->and($route?->config['php_socket'])->toBeNull()
        ->and($route?->config['tls'])->toBe([
            'cert_path' => '/home/gateway/.config/orbit/certs/feature-a.demo.crt',
            'key_path' => '/home/gateway/.config/orbit/certs/feature-a.demo.key',
        ])
        ->and($certificates->hosts)->toBe(['feature-a.demo'])
        ->and($route?->source_hash)->toBe(hash('sha256', $caddySite));
});

it('registers production workspace routes on ingress with a private backend site', function (): void {
    $appHost = Node::query()->whereKey(1)->firstOrFail();
    NodeRoleAssignment::query()
        ->where('node_id', $appHost->id)
        ->where('role', 'gateway')
        ->delete();

    $edge = Node::factory()->create([
        'name' => 'edge-1',
        'status' => 'active',
        'user' => 'orbit',
    ]);

    $router = Node::factory()->create([
        'name' => 'gateway-1',
        'status' => 'active',
        'wireguard_address' => '10.6.0.2',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $edge->id,
        'role' => 'ingress',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $router->id,
        'role' => 'router',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $appHost->id,
        'role' => 'app-prod',
        'status' => 'active',
        'settings' => ['ingress_node_id' => $edge->id],
    ]);

    App::query()->whereKey(1)->update([
        'domain' => 'demo.example.com',
        'environment' => 'production',
    ]);

    Node::query()->whereKey($appHost->id)->update([
        'wireguard_address' => '10.6.0.21',
        'user' => 'orbit',
    ]);

    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->firstOrFail();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    app(EnsureWorkspaceProxyRoute::class)->handle($workspace);

    $route = ProxyRoute::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect($route->node_id)->toBe($edge->id)
        ->and($route->config['placement'])->toBe('ingress')
        ->and($route->config['router_upstream'])->toBe([
            'node_id' => $router->id,
            'node' => 'gateway-1',
            'url' => 'http://10.6.0.2:80',
        ])
        ->and($route->config['router_artifact']['node_id'])->toBe($router->id)
        ->and($route->config['router_artifact']['source_hash'])->toHaveLength(64)
        ->and($route->config['router_backend_pool'])->toBe([
            [
                'node_id' => $appHost->id,
                'node' => 'gateway',
                'url' => 'http://10.6.0.21:8081',
            ],
        ])
        ->and($route->config['backend_artifacts'][0]['bind'])->toBe('10.6.0.21')
        ->and($route->config['backend_artifacts'][0]['source_hash'])->toHaveLength(64)
        ->and(collect($shell->runs)->contains(fn (array $run): bool => $run['node'] === $edge->id && str_contains($run['script'], 'sudo test -f /etc/caddy/Caddyfile')))->toBeTrue()
        ->and(collect($shell->runs)->contains(fn (array $run): bool => $run['node'] === $edge->id && str_contains($run['script'], 'sudo install -d -m 0755 /etc/caddy')))->toBeTrue()
        ->and(collect($shell->runs)->contains(fn (array $run): bool => $run['node'] === $edge->id && str_contains($run['script'], 'sudo tee /etc/caddy/Caddyfile >/dev/null')))->toBeTrue()
        ->and(collect($shell->runs)->contains(fn (array $run): bool => $run['node'] === $router->id && str_contains($run['script'], 'sudo test -f /etc/caddy/Caddyfile')))->toBeTrue()
        ->and(collect($shell->runs)->contains(fn (array $run): bool => $run['node'] === $router->id && str_contains($run['script'], 'sudo install -d -m 0755 /etc/caddy')))->toBeTrue()
        ->and(collect($shell->runs)->contains(fn (array $run): bool => $run['node'] === $router->id && str_contains($run['script'], 'sudo tee /etc/caddy/Caddyfile >/dev/null')))->toBeTrue()
        ->and(collect($shell->runs)->contains(fn (array $run): bool => $run['node'] === $appHost->id && str_contains($run['script'], 'sudo test -f /etc/caddy/Caddyfile')))->toBeTrue()
        ->and(collect($shell->runs)->contains(fn (array $run): bool => $run['node'] === $appHost->id && str_contains($run['script'], 'sudo install -d -m 0755 /etc/caddy')))->toBeTrue()
        ->and(collect($shell->runs)->contains(fn (array $run): bool => $run['node'] === $appHost->id && str_contains($run['script'], 'sudo tee /etc/caddy/Caddyfile >/dev/null')))->toBeTrue()
        ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, '/etc/caddy/sites/feature-a.demo.example.com.caddy')))->toBeTrue()
        ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, '/etc/caddy/sites/feature-a.demo.example.com.backend.caddy')))->toBeTrue()
        ->and((function () use ($shell): bool {
            foreach ($shell->scripts as $script) {
                if (! str_contains($script, 'feature-a.demo.example.com.backend.caddy')) {
                    continue;
                }

                if (preg_match("/printf %s '([^']+)' | base64 -d/", $script, $matches) === 1) {
                    $decoded = base64_decode($matches[1]);

                    if (str_contains($decoded, 'reverse_proxy http://orbit-ws-demo-feature-a')) {
                        return true;
                    }
                }
            }

            return false;
        })())->toBeTrue()
        ->and($certificates->hosts)->toBe(['feature-a.demo.example.com']);
});

it('starts configured app processes for the workspace after rendering runtime units', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->firstOrFail();

    OrbitProcess::factory()->forOwner($app)->create([
        'name' => 'vite',
        'command' => 'npm run dev -- --host=0.0.0.0',
        'restart_policy' => 'always',
        'crash_notification' => 'none',
        'sort_order' => 1,
    ]);

    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    $result = app(SetupWorkspace::class)->handle($app, $workspace, $node);

    expect($result['processes'])->toMatchArray([
        'status' => 'started',
        'count' => 1,
        'names' => ['vite'],
    ])
        ->and($certificates->hosts)->toBe(['feature-a.demo', 'feature-a.demo.beast'])
        ->and(collect($shell->scripts)->contains(
            fn (string $script): bool => str_contains($script, '/etc/systemd/system/orbit_demo_feature-a_vite.service')
        ))->toBeTrue()
        ->and($shell->scripts)->toContain("sudo systemctl start 'orbit_demo_feature-a_vite.service'");
});

it('reports converged for already-active workspace', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['action'])->toBe('converged');
});

it('reports adopted for new workspace with adoption flag', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node, isAdoption: true);

    expect($result['action'])->toBe('adopted');
});

it('skips setup steps when none are configured', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['setup_steps']['status'])->toBe('skipped');
    expect($result['setup_steps']['count'])->toBe(0);
});

it('runs setup steps when configured', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'echo "hello"',
        'timeout_seconds' => 60,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['setup_steps']['status'])->toBe('completed');
    expect($result['setup_steps']['count'])->toBe(1);

    $run = WorkspaceRun::query()
        ->where('workspace_id', $workspace->id)
        ->first();

    expect($run)->not->toBeNull();
    expect($run->status)->toBe('completed');
});

it('reports progress while setup steps are running', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'composer install --no-interaction',
        'timeout_seconds' => 1200,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 2,
        'command' => 'npm ci',
        'timeout_seconds' => 900,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $events = [];

    app(SetupWorkspace::class)->runSetupSteps(
        $workspace,
        $app,
        $node,
        function (string $event, WorkspaceStep $step, int $index, int $count) use (&$events): void {
            $events[] = [$event, $step->command, $index, $count];
        },
    );

    expect($events)->toBe([
        ['running', 'composer install --no-interaction', 1, 2],
        ['completed', 'composer install --no-interaction', 1, 2],
        ['running', 'npm ci', 2, 2],
        ['completed', 'npm ci', 2, 2],
    ]);
});

it('routes php and composer setup steps through the workspace runtime container', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'composer install --no-interaction',
        'timeout_seconds' => 1200,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 2,
        'command' => 'php artisan migrate --force',
        'timeout_seconds' => 300,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    app()->instance(RemoteShell::class, $shell);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    $stepRuns = array_values(array_filter($shell->runs, fn (array $run): bool => str_contains($run['script'], 'composer install') || str_contains($run['script'], 'php artisan')
    ));

    expect($stepRuns)->toHaveCount(2);

    expect($stepRuns[0]['script'])
        ->toContain("'docker'")
        ->toContain("'exec'")
        ->toContain("'orbit-ws-demo-feature-a'")
        ->toContain("'composer install --no-interaction'")
        ->toContain("'-w'")
        ->toContain("'/app'");

    expect($stepRuns[1]['script'])
        ->toContain("'docker'")
        ->toContain("'exec'")
        ->toContain("'orbit-ws-demo-feature-a'")
        ->toContain("'php artisan migrate --force'")
        ->toContain("'-w'")
        ->toContain("'/app'");
});

it('keeps non-php setup steps on the host', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'npm ci',
        'timeout_seconds' => 900,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    app()->instance(RemoteShell::class, $shell);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    $npmRun = collect($shell->runs)
        ->first(fn (array $run): bool => str_contains($run['script'], 'npm ci'));

    expect($npmRun['script'])->not->toContain("'docker'");
    expect($npmRun['options']['cwd'] ?? null)->toBe('/home/nckrtl/apps/demo/.worktrees/feature-a');
});

it('passes lifecycle environment into containerized setup steps', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'composer install',
        'timeout_seconds' => 1200,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    app()->instance(RemoteShell::class, $shell);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    $composerRun = collect($shell->runs)
        ->first(fn (array $run): bool => str_contains($run['script'], 'composer install'));

    expect($composerRun['script'])->toContain("'ORBIT_APP=demo'");
    expect($composerRun['script'])->toContain("'ORBIT_WORKSPACE_NAME=feature-a'");
});

it('skips setup steps when hash matches previous successful run', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'echo "hello"',
        'timeout_seconds' => 60,
    ]);

    WorkspaceRun::create([
        'workspace_id' => $workspace->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'status' => 'completed',
        'step_set_hash' => hash('sha256', json_encode([
            ['command' => 'echo "hello"', 'timeout' => 60],
        ])),
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['setup_steps']['status'])->toBe('skipped');
    expect($result['setup_steps']['message'])->toBe('Already up to date');
});

it('throws when setup step fails', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'exit 1',
        'timeout_seconds' => 60,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    app()->instance(RemoteShell::class, new SetupWorkspaceActionFailingShell);

    $setup = app(SetupWorkspace::class);

    expect(fn () => $setup->handle($app, $workspace, $node))
        ->toThrow(RuntimeException::class, 'Setup step failed: exit 1');

    $workspace->refresh();
    expect($workspace->lifecycle_status)->toBe(WorkspaceLifecycleStatus::SettingUp);
});

final class SetupWorkspaceActionTestShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array{node: int|null, script: string, options: array<string, mixed>}>
     */
    public array $runs = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = [
            'node' => $node->id,
            'script' => $script,
            'options' => $options,
        ];
        $this->scripts[] = $script;

        if (str_contains($script, 'sudo systemctl is-enabled "$service"')) {
            return new RemoteShellResult(exitCode: 0, stdout: json_encode([
                'exists' => false,
                'hash' => null,
                'enabled' => false,
            ], JSON_THROW_ON_ERROR)."\n", stderr: '', durationMs: 1);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class SetupWorkspaceActionFailingShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'step failed', durationMs: 1);
    }
}

final class SetupWorkspaceActionTestCertificateInstaller implements SiteCertificateInstaller
{
    /**
     * @var list<string>
     */
    public array $hosts = [];

    public function ensureFor(Node $node, string $host): array
    {
        $this->hosts[] = $host;

        return $this->expectedPathsFor($node, $host);
    }

    public function expectedPathsFor(Node $node, string $host): array
    {
        return [
            'cert' => "/home/gateway/.config/orbit/certs/{$host}.crt",
            'key' => "/home/gateway/.config/orbit/certs/{$host}.key",
        ];
    }
}

function expectWorkspaceFrankenPhpRuntimeProcess(Workspace $workspace): void
{
    $workspace->loadMissing('app');

    $process = OrbitProcess::query()
        ->ownedBy($workspace)
        ->where('name', "frankenphp-{$workspace->app->name}-{$workspace->name}")
        ->first();

    expect($process)->not->toBeNull()
        ->and($process?->node_id)->toBe($workspace->app->node_id)
        ->and($process?->command)->toBe('frankenphp')
        ->and($process?->restart_policy)->toBe(ProcessRestartPolicy::Always)
        ->and($process?->crash_notification)->toBe(ProcessCrashNotification::None)
        ->and($process?->runtime)->toBe(ProcessRuntime::Docker)
        ->and($process?->tool)->toBeNull()
        ->and($process?->runtime_config)->toMatchArray([
            'container_name' => 'orbit-ws-demo-feature-a',
            'php_ini_path' => '/etc/orbit/workspaces/demo-feature-a.ini',
            'container_spec_hash_label' => 'orbit.workspace.spec_hash',
        ]);
}
