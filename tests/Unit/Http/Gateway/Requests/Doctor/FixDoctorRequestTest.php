<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Doctor;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Doctor\FixDoctorRequest;
use App\Http\Gateway\Responses\Doctor\DoctorRunResponse;
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

it('resolves to POST /api/doctor/fix', function (): void {
    $request = new FixDoctorRequest(mode: 'restore', families: ['node']);

    expect($request->resolveEndpoint())->toBe('/api/doctor/fix');
    expect($request->getMethod())->toBe(Method::POST);
});

it('serializes doctor fix filters into the body', function (): void {
    $issues = [['family' => 'proxy', 'key' => 'proxy.route_missing']];
    $request = new FixDoctorRequest(
        mode: 'restore',
        families: ['proxy'],
        issues: $issues,
        node: 'app-1',
        self: true,
        app: 'docs',
        workspace: 'main',
        key: 'proxy.route_missing',
        dryRun: true,
    );

    expect($request->body()->all())->toBe([
        'mode' => 'restore',
        'families' => ['proxy'],
        'issues' => $issues,
        'node' => 'app-1',
        'self' => true,
        'app' => 'docs',
        'workspace' => 'main',
        'key' => 'proxy.route_missing',
        'dry_run' => true,
    ]);
});

it('returns a DoctorRunResponse DTO', function (): void {
    $mock = new MockClient([
        FixDoctorRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'doctor' => [
                        'healthy' => true,
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new FixDoctorRequest(mode: 'restore', families: ['node']))->dto();

    expect($dto)->toBeInstanceOf(DoctorRunResponse::class);
    expect($dto->doctor)->toBe(['healthy' => true]);
});
