<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Schedules;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\AddScheduleRequest;
use App\Http\Gateway\Requests\Schedules\ListSchedulesRequest;
use App\Http\Gateway\Requests\Schedules\RemoveScheduleRequest;
use App\Http\Gateway\Requests\Schedules\RunScheduleRequest;
use App\Http\Gateway\Requests\Schedules\ShowScheduleLogsRequest;
use App\Http\Gateway\Requests\Schedules\ShowScheduleRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleListResponse;
use App\Http\Gateway\Responses\Schedules\ScheduleShowResponse;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

it('posts schedule add payloads to the gateway', function (): void {
    $request = new AddScheduleRequest(
        name: 'laravel-scheduler',
        app: 'docs',
        node: null,
        interval: 'every minute',
        timezone: 'UTC',
        command: 'php artisan schedule:run',
        script: null,
    );

    expect($request->resolveEndpoint())->toBe('/api/schedules');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe([
        'name' => 'laravel-scheduler',
        'app' => 'docs',
        'interval' => 'every minute',
        'timezone' => 'UTC',
        'command' => 'php artisan schedule:run',
    ]);
});

it('resolves schedule read endpoints and query filters', function (): void {
    $list = new ListSchedulesRequest(app: 'docs', node: null);
    $show = new ShowScheduleRequest(name: 'laravel-scheduler', app: 'docs', node: null);

    expect($list->resolveEndpoint())->toBe('/api/schedules');
    expect($list->getMethod())->toBe(Method::GET);
    expect($list->query()->all())->toBe(['app' => 'docs']);
    expect($show->resolveEndpoint())->toBe('/api/schedules/laravel-scheduler');
    expect($show->getMethod())->toBe(Method::GET);
    expect($show->query()->all())->toBe(['app' => 'docs']);
});

it('resolves schedule remove endpoint and query filters', function (): void {
    $request = new RemoveScheduleRequest(name: 'laravel-scheduler', app: 'docs');

    expect($request->resolveEndpoint())->toBe('/api/schedules/laravel-scheduler');
    expect($request->getMethod())->toBe(Method::DELETE);
    expect($request->query()->all())->toBe(['app' => 'docs']);
    expect($request->body()->all())->toBe([
        'destructive_consent' => true,
        'destructive_consent_source' => 'force',
    ]);
});

it('resolves manual schedule run endpoint and query filters', function (): void {
    $request = new RunScheduleRequest(name: 'laravel-scheduler', app: 'docs');

    expect($request->resolveEndpoint())->toBe('/api/schedules/laravel-scheduler/run');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->query()->all())->toBe(['app' => 'docs']);
});

it('resolves schedule logs endpoint and query filters', function (): void {
    $request = new ShowScheduleLogsRequest(name: 'laravel-scheduler', app: 'docs', run: 18, lines: 10);

    expect($request->resolveEndpoint())->toBe('/api/schedules/laravel-scheduler/logs');
    expect($request->getMethod())->toBe(Method::GET);
    expect($request->query()->all())->toBe(['app' => 'docs', 'run' => 18, 'lines' => 10]);
});

it('returns schedule list and show response DTOs with meta', function (): void {
    $mock = new MockClient([
        ListSchedulesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'schedules' => [
                        ['name' => 'laravel-scheduler'],
                    ],
                ],
                'meta' => ['app' => 'docs', 'node' => null, 'count' => 1],
            ],
        ], 200),
        ShowScheduleRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'schedule' => ['name' => 'laravel-scheduler'],
                ],
                'meta' => ['app' => 'docs', 'node' => null],
            ],
        ], 200),
    ]);

    $connector = app(GatewayConnector::class);
    $connector->withMockClient($mock);

    $listDto = $connector->send(new ListSchedulesRequest(app: 'docs'))->dto();
    $showDto = $connector->send(new ShowScheduleRequest(name: 'laravel-scheduler', app: 'docs'))->dto();

    expect($listDto)->toBeInstanceOf(ScheduleListResponse::class);
    expect($listDto->schedules)->toBe([['name' => 'laravel-scheduler']]);
    expect($listDto->meta)->toBe(['app' => 'docs', 'node' => null, 'count' => 1]);
    expect($showDto)->toBeInstanceOf(ScheduleShowResponse::class);
    expect($showDto->schedule)->toBe(['name' => 'laravel-scheduler']);
    expect($showDto->meta)->toBe(['app' => 'docs', 'node' => null]);
});
