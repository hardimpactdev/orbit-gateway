<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\AppInstanceRuntimeRequirementsData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Services\Apps\AppRuntimeRequirementProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports missing required PHP extensions with stable issue codes', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
    $app = App::factory()->for($node, 'node')->create(['name' => 'billing']);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'runtime_requirements' => new AppInstanceRuntimeRequirementsData(
            php_extensions: ['redis', 'intl'],
        ),
    ]);

    app()->instance(RemoteShell::class, new class implements RemoteShell
    {
        public function run(Node $node, string $command, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: "redis\npdo\n",
                stderr: '',
                durationMs: 10,
            );
        }
    });

    $issues = app(AppRuntimeRequirementProbe::class)->drift($instance);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->family)->toBe('app')
        ->and($issues[0]->key)->toBe('app.runtime_extension_missing')
        ->and($issues[0]->summary)->toContain('intl');
});

it('reports unverifiable PHP extension state when the runtime cannot be queried', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
    $app = App::factory()->for($node, 'node')->create(['name' => 'billing']);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'runtime_requirements' => new AppInstanceRuntimeRequirementsData(
            php_extensions: ['redis'],
        ),
    ]);

    app()->instance(RemoteShell::class, new class implements RemoteShell
    {
        public function run(Node $node, string $command, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: 'container not running',
                durationMs: 10,
            );
        }
    });

    $issues = app(AppRuntimeRequirementProbe::class)->drift($instance);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->key)->toBe('app.runtime_extensions_unverifiable');
});
