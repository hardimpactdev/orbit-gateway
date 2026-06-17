<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores app scoped schedule intent and relates latest run history', function (): void {
    $app = App::factory()->create(['name' => 'docs']);

    $schedule = Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
        'interval' => 'every minute',
        'timezone' => 'Europe/Amsterdam',
        'execution_type' => 'command',
        'execution_value' => 'php artisan schedule:run',
    ]);

    ScheduleRun::factory()->create([
        'schedule_key' => 'app:docs:laravel-scheduler',
        'started_at' => '2026-05-06 12:00:00',
        'status' => 'failed',
        'exit_code' => 1,
    ]);
    $latestRun = ScheduleRun::factory()->create([
        'schedule_key' => 'app:docs:laravel-scheduler',
        'started_at' => '2026-05-06 12:01:00',
        'status' => 'completed',
        'exit_code' => 0,
    ]);

    expect($schedule->app->is($app))->toBeTrue()
        ->and($app->schedules()->first()->is($schedule))->toBeTrue()
        ->and($schedule->enabled)->toBeTrue()
        ->and($schedule->latestRun?->is($latestRun))->toBeTrue()
        ->and($schedule->runs)->toHaveCount(2);
});

it('stores node scoped schedule intent', function (): void {
    $node = Node::factory()->create(['name' => 'app-1']);

    $schedule = Schedule::factory()->forNode($node)->create([
        'name' => 'backup',
        'schedule_key' => 'node:app-1:backup',
        'execution_type' => 'script',
        'execution_value' => '/opt/orbit/scripts/backup',
    ]);

    expect($schedule->node->is($node))->toBeTrue()
        ->and($node->schedules()->first()->is($schedule))->toBeTrue()
        ->and($schedule->scope)->toBe('node')
        ->and($schedule->execution_type)->toBe('script');
});

it('keeps schedule keys globally unique', function (): void {
    Schedule::factory()->create(['schedule_key' => 'app:docs:laravel-scheduler']);

    expect(fn () => Schedule::factory()->create(['schedule_key' => 'app:docs:laravel-scheduler']))
        ->toThrow(QueryException::class);
});
