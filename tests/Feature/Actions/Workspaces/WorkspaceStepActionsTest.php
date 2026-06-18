<?php

declare(strict_types=1);

use App\Actions\Workspaces\AddWorkspaceStep;
use App\Actions\Workspaces\RemoveWorkspaceStep;
use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('orders workspace setup and teardown steps independently', function (): void {
    $app = App::factory()->create();
    $addStep = app(AddWorkspaceStep::class);
    $removeStep = app(RemoveWorkspaceStep::class);

    $first = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        command: 'composer install',
    );
    $second = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        command: 'php artisan migrate',
    );
    $inserted = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Setup,
        command: 'npm run build',
        beforeStepId: $second->id,
    );
    $teardown = $addStep->handle(
        appId: $app->id,
        phase: WorkspaceLifecyclePhase::Teardown,
        command: 'php artisan down',
    );

    expect(WorkspaceStep::query()
        ->where('app_id', $app->id)
        ->where('phase', WorkspaceLifecyclePhase::Setup)
        ->orderBy('sort_order')
        ->pluck('command')
        ->all())->toBe([
            'composer install',
            'npm run build',
            'php artisan migrate',
        ])
        ->and($teardown->sort_order)->toBe(1)
        ->and($first->timeoutSeconds())->toBe(WorkspaceStep::DEFAULT_TIMEOUT_SECONDS);

    $removeStep->handle($inserted);

    expect(WorkspaceStep::query()
        ->where('app_id', $app->id)
        ->where('phase', WorkspaceLifecyclePhase::Setup)
        ->orderBy('sort_order')
        ->pluck('sort_order', 'command')
        ->all())->toBe([
            'composer install' => 1,
            'php artisan migrate' => 2,
        ]);
});
