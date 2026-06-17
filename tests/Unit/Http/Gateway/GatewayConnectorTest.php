<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway;

use App\Http\Gateway\GatewayConnector;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

it('resolves base url from local gateway settings', function (): void {
    $connector = new GatewayConnector;

    expect($connector->resolveBaseUrl())->toBe('https://10.6.0.2');
});

it('configures verify, allow_redirects, and timeouts', function (): void {
    $connector = new GatewayConnector;
    $config = $connector->config()->all();

    expect($config)
        ->toHaveKey('verify', '/path/to/ca.pem')
        ->toHaveKey('allow_redirects', false)
        ->toHaveKey('timeout', 900)
        ->toHaveKey('connect_timeout', 10);
});

it('sends Accept: application/json by default', function (): void {
    $connector = new GatewayConnector;
    $headers = $connector->headers()->all();

    expect($headers)->toHaveKey('Accept', 'application/json');
});

it('can identify scheduler-originated gateway clients without changing transport trust', function (): void {
    $connector = GatewayConnector::forScheduler();
    $headers = $connector->headers()->all();
    $config = $connector->config()->all();

    expect($headers)
        ->toHaveKey('Accept', 'application/json')
        ->toHaveKey('X-Orbit-Client', 'scheduler')
        ->and($config)
        ->toHaveKey('verify', '/path/to/ca.pem')
        ->toHaveKey('allow_redirects', false);
});
