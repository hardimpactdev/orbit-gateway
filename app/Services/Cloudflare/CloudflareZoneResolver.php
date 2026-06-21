<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

use App\Http\Gateway\GatewayApiException;
use App\Models\App;

final readonly class CloudflareZoneResolver
{
    public function __construct(private CloudflareClient $client) {}

    /**
     * @return array{id: string, name: string, status: string}
     */
    public function resolveZone(string $identifier): array
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            throw new GatewayApiException(
                message: 'A Cloudflare zone is required.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'zone'],
            );
        }

        if ($this->looksLikeZoneId($identifier)) {
            return ['id' => $identifier, 'name' => $identifier, 'status' => 'unknown'];
        }

        foreach ($this->client->zones() as $zone) {
            if ($zone['name'] === $identifier) {
                return $zone;
            }
        }

        throw new GatewayApiException(
            message: 'The selected Cloudflare zone was not found.',
            errorCode: 'validation_failed',
            errorMeta: ['field' => 'zone', 'zone' => $identifier],
        );
    }

    /**
     * @return array{id: string, name: string, status: string}
     */
    public function resolveZoneForRecordName(string $recordName): array
    {
        $recordName = trim($recordName);

        if ($recordName === '') {
            throw new GatewayApiException(
                message: 'A DNS record name is required.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'name'],
            );
        }

        $zones = collect($this->client->zones())
            ->sortByDesc(fn (array $zone): int => strlen($zone['name']))
            ->values();

        foreach ($zones as $zone) {
            if ($recordName === $zone['name'] || str_ends_with($recordName, ".{$zone['name']}")) {
                return $zone;
            }
        }

        throw new GatewayApiException(
            message: 'The DNS record name does not belong to a visible Cloudflare zone.',
            errorCode: 'validation_failed',
            errorMeta: ['field' => 'name', 'name' => $recordName],
        );
    }

    /**
     * @return array{app: App, zone: array{id: string, name: string, status: string}}
     */
    public function resolveAppZone(string $appName): array
    {
        $appName = trim($appName);

        if ($appName === '') {
            throw new GatewayApiException(
                message: 'An app name is required.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'app'],
            );
        }

        $app = App::query()->where('name', $appName)->first();

        if (! $app instanceof App) {
            throw new GatewayApiException(
                message: 'The selected app was not found.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'app', 'app' => $appName],
            );
        }

        $domain = is_string($app->domain) ? trim($app->domain) : '';

        if ($domain === '' || ! str_contains($domain, '.')) {
            throw new GatewayApiException(
                message: 'The app has no Cloudflare-backed domain.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'app', 'app' => $appName],
            );
        }

        return [
            'app' => $app,
            'zone' => $this->resolveZoneForRecordName($domain),
        ];
    }

    /**
     * @return array{zone: array{id: string, name: string, status: string}, app: App|null}
     */
    public function resolveZoneOrApp(string $identifier): array
    {
        $app = App::query()->where('name', $identifier)->first();

        if ($app instanceof App) {
            $resolved = $this->resolveAppZone($identifier);

            return [
                'zone' => $resolved['zone'],
                'app' => $resolved['app'],
            ];
        }

        return [
            'zone' => $this->resolveZone($identifier),
            'app' => null,
        ];
    }

    private function looksLikeZoneId(string $identifier): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $identifier) === 1;
    }
}
