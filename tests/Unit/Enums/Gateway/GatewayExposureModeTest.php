<?php

declare(strict_types=1);

use App\Enums\Gateway\GatewayExposureMode;

it('parses supported gateway exposure modes', function (string $input, string $value): void {
    expect(GatewayExposureMode::parse($input)->value)->toBe($value);
})->with([
    'router colocated' => ['router-colocated', 'router-colocated'],
    'gateway direct' => ['gateway-direct', 'gateway-direct'],
    'trimmed' => [' gateway-direct ', 'gateway-direct'],
]);

it('rejects unsupported gateway exposure modes', function (): void {
    expect(fn () => GatewayExposureMode::parse('public-router'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported gateway exposure mode [public-router]. Expected router-colocated or gateway-direct.');
});
