<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Php;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Php\ShowPhpRuntimeRequest;
use App\Http\Gateway\Requests\Php\UsePhpRuntimeRequest;
use App\Http\Gateway\Responses\Php\PhpRuntimeResponse;
use App\Http\Gateway\Responses\Php\PhpRuntimeUseResponse;
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

it('serializes PHP runtime read filters', function (): void {
    $request = new ShowPhpRuntimeRequest(app: 'docs', workspace: 'feature-docs', node: 'app-1', live: true);

    expect($request->resolveEndpoint())->toBe('/api/php/runtime')
        ->and($request->getMethod())->toBe(Method::GET)
        ->and($request->query()->all())->toBe([
            'app' => 'docs',
            'workspace' => 'feature-docs',
            'node' => 'app-1',
            'live' => true,
        ]);
});

it('serializes PHP runtime write payload', function (): void {
    $request = new UsePhpRuntimeRequest(version: '8.5', app: 'docs', workspace: null, node: null, inherit: false, cli: false);

    expect($request->resolveEndpoint())->toBe('/api/php/use')
        ->and($request->getMethod())->toBe(Method::POST)
        ->and($request->body()->all())->toBe([
            'version' => '8.5',
            'app' => 'docs',
            'inherit' => false,
            'cli' => false,
        ]);
});

it('returns typed response DTOs from gateway envelopes', function (): void {
    $mock = new MockClient([
        ShowPhpRuntimeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'php' => ['node' => 'app-1'],
                ],
                'meta' => ['live' => true],
            ],
        ], 200),
        UsePhpRuntimeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'php' => ['node' => 'app-1'],
                    'result' => ['target' => 'app'],
                ],
                'meta' => ['warnings' => []],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    expect($connector->send(new ShowPhpRuntimeRequest)->dto())->toBeInstanceOf(PhpRuntimeResponse::class)
        ->and($connector->send(new UsePhpRuntimeRequest(version: '8.5', app: 'docs'))->dto())->toBeInstanceOf(PhpRuntimeUseResponse::class);
});
