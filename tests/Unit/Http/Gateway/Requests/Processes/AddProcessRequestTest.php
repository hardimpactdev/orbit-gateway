<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Processes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\AddProcessRequest;
use App\Http\Gateway\Responses\Processes\ProcessAddResponse;
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

it('resolves to POST /api/processes', function (): void {
    $request = new AddProcessRequest(app: 'docs', name: 'vite', command: 'npm run dev');

    expect($request->resolveEndpoint())->toBe('/api/processes');
    expect($request->getMethod())->toBe(Method::POST);
});

it('serializes process creation body', function (): void {
    $request = new AddProcessRequest(
        app: 'docs',
        name: 'vite',
        command: 'npm run dev -- --host=0.0.0.0',
        restartPolicy: 'always',
        crashNotification: 'agent_ide',
        start: true,
    );

    expect($request->body()->all())->toBe([
        'app' => 'docs',
        'name' => 'vite',
        'command' => 'npm run dev -- --host=0.0.0.0',
        'restart_policy' => 'always',
        'crash_notification' => 'agent_ide',
        'start' => true,
    ]);
});

it('omits the runtime field from the request body when none was supplied', function (): void {
    $request = new AddProcessRequest(app: 'docs', name: 'vite', command: 'npm run dev');

    expect($request->body()->all())->not->toHaveKey('runtime');
});

it('serializes an explicit runtime override into the request body', function (): void {
    $request = new AddProcessRequest(
        app: 'docs',
        name: 'legacy',
        command: './legacy.sh',
        runtime: 'systemd',
    );

    expect($request->body()->all())->toMatchArray([
        'app' => 'docs',
        'name' => 'legacy',
        'command' => './legacy.sh',
        'runtime' => 'systemd',
    ]);
});

it('returns a ProcessAddResponse DTO with warnings', function (): void {
    $mock = new MockClient([
        AddProcessRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'process' => ['name' => 'vite', 'app' => 'docs'],
                    'runtime_units' => [['name' => 'orbit_docs_main_vite', 'context' => 'main']],
                ],
                'meta' => [
                    'warnings' => [
                        ['code' => 'process.runtime_unit_missing'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new AddProcessRequest(app: 'docs', name: 'vite', command: 'npm run dev'))->dto();

    expect($dto)->toBeInstanceOf(ProcessAddResponse::class);
    expect($dto->data['process']['name'])->toBe('vite');
    expect($dto->warnings)->toBe([
        ['code' => 'process.runtime_unit_missing'],
    ]);
});
