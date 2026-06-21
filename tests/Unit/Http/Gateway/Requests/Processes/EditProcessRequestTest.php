<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Processes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\EditProcessRequest;
use App\Http\Gateway\Responses\Processes\ProcessEditResponse;
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

it('resolves to PATCH /api/processes/{name}', function (): void {
    $request = new EditProcessRequest(app: 'docs', name: 'vite', command: 'npm run dev');

    expect($request->resolveEndpoint())->toBe('/api/processes/vite');
    expect($request->getMethod())->toBe(Method::PATCH);
});

it('serializes only supplied editable fields', function (): void {
    $request = new EditProcessRequest(
        app: 'docs',
        name: 'vite',
        command: 'npm run dev -- --host=0.0.0.0',
        crashNotification: 'agent_ide',
        restart: true,
    );

    expect($request->body()->all())->toBe([
        'app' => 'docs',
        'command' => 'npm run dev -- --host=0.0.0.0',
        'crash_notification' => 'agent_ide',
        'restart' => true,
    ]);
});

it('omits the runtime field when none was supplied', function (): void {
    $request = new EditProcessRequest(app: 'docs', name: 'vite', command: 'npm run dev');

    expect($request->body()->all())->not->toHaveKey('runtime');
});

it('serializes a runtime change into the request body', function (): void {
    $request = new EditProcessRequest(
        app: 'docs',
        name: 'queue',
        runtime: 'systemd',
    );

    expect($request->body()->all())->toMatchArray([
        'app' => 'docs',
        'runtime' => 'systemd',
    ]);
});

it('returns a ProcessEditResponse DTO with warnings', function (): void {
    $mock = new MockClient([
        EditProcessRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'process' => ['name' => 'vite', 'app' => 'docs'],
                    'changed' => ['command'],
                    'runtime_units' => [['name' => 'orbit_docs_main_vite', 'context' => 'main']],
                ],
                'meta' => [
                    'warnings' => [
                        ['code' => 'process.runtime_unit_restart_failed'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new EditProcessRequest(app: 'docs', name: 'vite', command: 'npm run dev'))->dto();

    expect($dto)->toBeInstanceOf(ProcessEditResponse::class);
    expect($dto->data['changed'])->toBe(['command']);
    expect($dto->warnings)->toBe([
        ['code' => 'process.runtime_unit_restart_failed'],
    ]);
});
