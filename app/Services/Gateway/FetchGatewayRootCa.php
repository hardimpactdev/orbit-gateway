<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Gateway\ShowGatewayCaRootRequest;
use RuntimeException;
use Saloon\Http\Response;

final readonly class FetchGatewayRootCa
{
    private const int TIMEOUT = 10;

    public function handle(string $gatewayIp): RootCaFetchResult
    {
        $response = $this->fetchRootCa($gatewayIp);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to fetch CA from gateway at http://{$gatewayIp}/api/ca/root: HTTP {$response->status()}",
            );
        }

        $decoded = $this->decodeJsonBody($response);
        $rootCa = is_array($decoded)
            ? $decoded['success']['data']['root_ca'] ?? $decoded['data']['root_ca'] ?? null
            : $response->body();

        if (! is_string($rootCa) || $rootCa === '' || str_starts_with($rootCa, '{')) {
            if (is_string($rootCa) && str_starts_with($rootCa, '{')) {
                $decoded = json_decode($rootCa, true);
                $rootCa = $decoded['data']['root_ca']
                    ?? $decoded['success']['data']['root_ca']
                    ?? null;
            }
        }

        if (! is_string($rootCa) || $rootCa === '') {
            throw new RuntimeException("Gateway at {$gatewayIp} returned an invalid or empty CA.");
        }

        if (! str_contains($rootCa, '-----BEGIN CERTIFICATE-----') || ! str_contains($rootCa, '-----END CERTIFICATE-----')) {
            throw new RuntimeException("Gateway at {$gatewayIp} returned non-PEM content.");
        }

        $sha256 = hash('sha256', $rootCa);
        $sourceUrl = "https://{$gatewayIp}/api/ca/root";

        return new RootCaFetchResult(
            pem: $rootCa,
            sha256: $sha256,
            sourceUrl: $sourceUrl,
        );
    }

    private function fetchRootCa(string $gatewayIp): Response
    {
        $response = new GatewayConnector(
            baseUrl: "http://{$gatewayIp}",
            caPemPath: false,
            timeout: self::TIMEOUT,
        )->send(new ShowGatewayCaRootRequest);

        if (! in_array($response->status(), [301, 302, 307, 308], true)) {
            return $response;
        }

        $location = $response->header('Location');

        if (! is_string($location) || ! $this->isSameGatewayCaLocation($location, $gatewayIp)) {
            return $response;
        }

        return new GatewayConnector(
            baseUrl: "https://{$gatewayIp}",
            caPemPath: false,
            timeout: self::TIMEOUT,
        )->send(new ShowGatewayCaRootRequest);
    }

    private function isSameGatewayCaLocation(string $location, string $gatewayIp): bool
    {
        $parts = parse_url($location);

        return ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === $gatewayIp
            && ($parts['path'] ?? null) === '/api/ca/root';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonBody(Response $response): ?array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
