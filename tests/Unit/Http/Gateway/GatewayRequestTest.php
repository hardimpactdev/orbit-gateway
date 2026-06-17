<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\GatewayRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

function makeProbeRequest(): GatewayRequest
{
    return new class extends GatewayRequest
    {
        protected Method $method = Method::GET;

        public function resolveEndpoint(): string
        {
            return '/api/probe';
        }

        public function createDtoFromResponse(Response $response): array
        {
            return $this->unwrapData($response);
        }
    };
}

it('unwraps success.data envelope into an array', function (): void {
    $mock = new MockClient([
        '*' => MockResponse::make([
            'success' => ['data' => ['ok' => true]],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(makeProbeRequest())->dto();

    expect($dto)->toBe(['ok' => true]);
});

it('treats top-level "doctor" payload as data without rewrapping', function (): void {
    $mock = new MockClient([
        '*' => MockResponse::make([
            'doctor' => ['issues' => 0],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(makeProbeRequest())->dto();

    expect($dto)->toBe(['doctor' => ['issues' => 0]]);
});

it('throws GatewayApiException on error envelope at HTTP 200', function (): void {
    $mock = new MockClient([
        '*' => MockResponse::make([
            'error' => [
                'code' => 'status_mismatch',
                'message' => 'HTTP 200 but envelope says error',
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    expect(fn () => $connector->send(makeProbeRequest())->dto())
        ->toThrow(GatewayApiException::class, 'HTTP 200 but envelope says error');
});

it('throws GatewayApiException with code/meta on error envelope at HTTP 4xx', function (): void {
    $mock = new MockClient([
        '*' => MockResponse::make([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'The given data was invalid.',
                'meta' => ['field' => 'name'],
            ],
        ], 422),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    try {
        $connector->send(makeProbeRequest())->dto();
        $this->fail('Expected GatewayApiException');
    } catch (GatewayApiException $e) {
        expect($e->getMessage())->toBe('The given data was invalid.');
        expect($e->errorCode())->toBe('validation_failed');
        expect($e->errorMeta())->toBe(['field' => 'name']);
    }
});

it('throws GatewayApiException on HTTP 5xx with non-JSON body', function (): void {
    $mock = new MockClient([
        '*' => MockResponse::make('<html>Service Unavailable</html>', 503),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    expect(fn () => $connector->send(makeProbeRequest())->dto())
        ->toThrow(GatewayApiException::class);
});
