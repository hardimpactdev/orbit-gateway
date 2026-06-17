<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

use App\Http\Gateway\GatewayApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final readonly class CloudflareClient
{
    private const string BaseUrl = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private ?string $apiToken,
        private ?string $apiEmail,
    ) {}

    /**
     * @return array<int, array{id: string, name: string, status: string}>
     */
    public function zones(): array
    {
        $response = $this->request('get', '/zones', ['per_page' => 50]);

        return collect($response['result'] ?? [])
            ->filter(fn (mixed $zone): bool => is_array($zone))
            ->map(fn (array $zone): array => [
                'id' => (string) ($zone['id'] ?? ''),
                'name' => (string) ($zone['name'] ?? ''),
                'status' => (string) ($zone['status'] ?? ''),
            ])
            ->filter(fn (array $zone): bool => $zone['id'] !== '' && $zone['name'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, zone?: string, type: string, name: string, content: string, proxied: bool}>
     */
    public function dnsRecords(string $zoneId, ?string $name = null, ?string $type = null): array
    {
        $params = ['per_page' => 100];

        if ($name !== null && $name !== '') {
            $params['name'] = $name;
        }

        if ($type !== null && $type !== '') {
            $params['type'] = $type;
        }

        $response = $this->request('get', "/zones/{$zoneId}/dns_records", $params);

        return collect($response['result'] ?? [])
            ->filter(fn (mixed $record): bool => is_array($record))
            ->map(fn (array $record): array => $this->recordFromProvider($record))
            ->filter(fn (array $record): bool => $record['id'] !== '' && $record['type'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array{id: string, zone?: string, type: string, name: string, content: string, proxied: bool}
     */
    public function dnsRecord(string $zoneId, string $recordId): array
    {
        $response = $this->request('get', "/zones/{$zoneId}/dns_records/{$recordId}");
        $record = $response['result'] ?? [];

        if (! is_array($record)) {
            throw new GatewayApiException(
                message: 'The selected Cloudflare DNS record was not found.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'record_id', 'record_id' => $recordId],
            );
        }

        return $this->recordFromProvider($record);
    }

    /**
     * @return array{id: string, zone?: string, type: string, name: string, content: string, proxied: bool}
     */
    public function addDnsRecord(string $zoneId, string $name, string $content, string $type, bool $proxied): array
    {
        $response = $this->request('post', "/zones/{$zoneId}/dns_records", [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'proxied' => $proxied,
        ]);

        $record = $response['result'] ?? [];

        if (! is_array($record)) {
            $record = [];
        }

        return $this->recordFromProvider([
            ...$record,
            'type' => $record['type'] ?? $type,
            'name' => $record['name'] ?? $name,
            'content' => $record['content'] ?? $content,
            'proxied' => $record['proxied'] ?? $proxied,
        ]);
    }

    public function removeDnsRecord(string $zoneId, string $recordId): void
    {
        $this->request('delete', "/zones/{$zoneId}/dns_records/{$recordId}");
    }

    public function flushCache(string $zoneId): void
    {
        $this->request('post', "/zones/{$zoneId}/purge_cache", ['purge_everything' => true]);
    }

    public function setSslMode(string $zoneId, string $mode): void
    {
        $this->request('patch', "/zones/{$zoneId}/settings/ssl", ['value' => $mode]);
    }

    public function createCacheRule(string $zoneId): bool
    {
        $rulesets = $this->rulesets($zoneId);
        $existing = collect($rulesets)->firstWhere('phase', 'http_request_cache_settings');
        $rule = $this->cacheSettingsRule();

        if (is_array($existing)) {
            if ($this->containsEquivalentCacheRule($existing)) {
                return true;
            }

            $this->request('put', "/zones/{$zoneId}/rulesets/".(string) $existing['id'], [
                'name' => (string) ($existing['name'] ?? 'default'),
                'kind' => (string) ($existing['kind'] ?? 'zone'),
                'phase' => 'http_request_cache_settings',
                'rules' => [$rule],
            ]);

            return false;
        }

        $this->request('post', "/zones/{$zoneId}/rulesets", [
            'name' => 'default',
            'kind' => 'zone',
            'phase' => 'http_request_cache_settings',
            'rules' => [$rule],
        ]);

        return false;
    }

    public function removeCacheRule(string $zoneId): bool
    {
        $rulesets = $this->rulesets($zoneId);
        $existing = collect($rulesets)->firstWhere('phase', 'http_request_cache_settings');

        if (! is_array($existing) || ! is_string($existing['id'] ?? null) || $existing['id'] === '') {
            return false;
        }

        $this->request('delete', "/zones/{$zoneId}/rulesets/{$existing['id']}");

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rulesets(string $zoneId): array
    {
        $response = $this->request('get', "/zones/{$zoneId}/rulesets");

        return collect($response['result'] ?? [])
            ->filter(fn (mixed $ruleset): bool => is_array($ruleset))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheSettingsRule(): array
    {
        return [
            'action' => 'set_cache_settings',
            'action_parameters' => [
                'cache' => true,
                'browser_ttl' => ['mode' => 'respect_origin'],
            ],
            'expression' => 'true',
            'description' => 'Cache everything - respect origin Cache-Control',
            'enabled' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $ruleset
     */
    private function containsEquivalentCacheRule(array $ruleset): bool
    {
        $rules = $ruleset['rules'] ?? [];

        if (! is_array($rules)) {
            return false;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $parameters = $rule['action_parameters'] ?? [];
            $browserTtl = is_array($parameters) ? ($parameters['browser_ttl'] ?? []) : [];

            if (($rule['action'] ?? null) === 'set_cache_settings'
                && ($parameters['cache'] ?? null) === true
                && is_array($browserTtl)
                && ($browserTtl['mode'] ?? null) === 'respect_origin') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{id: string, zone?: string, type: string, name: string, content: string, proxied: bool}
     */
    private function recordFromProvider(array $record): array
    {
        return [
            'id' => (string) ($record['id'] ?? ''),
            'type' => (string) ($record['type'] ?? ''),
            'name' => (string) ($record['name'] ?? ''),
            'content' => (string) ($record['content'] ?? ''),
            'proxied' => (bool) ($record['proxied'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $data = []): array
    {
        $http = $this->http();
        $url = self::BaseUrl.$path;

        $response = match ($method) {
            'get' => $http->get($url, $data),
            'post' => $http->post($url, $data),
            'put' => $http->put($url, $data),
            'patch' => $http->patch($url, $data),
            'delete' => $http->delete($url, $data),
            default => throw new GatewayApiException('Unsupported Cloudflare request method.', 'cloudflare_unavailable'),
        };

        $body = $response->json();
        $providerSucceeded = is_array($body) && ($body['success'] ?? true) !== false;

        if (! $response->successful() || ! $providerSucceeded) {
            $message = $this->providerMessage(is_array($body) ? $body : []);

            throw new GatewayApiException(
                message: $message !== null ? "Cloudflare is unavailable: {$message}" : 'Cloudflare is unavailable.',
                errorCode: 'cloudflare_unavailable',
                errorMeta: array_filter([
                    'provider_status' => $response->status(),
                    'provider_message' => $message,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
            );
        }

        if (! is_array($body)) {
            throw new GatewayApiException('Cloudflare returned an invalid response.', 'cloudflare_unavailable');
        }

        return $body;
    }

    private function http(): PendingRequest
    {
        if (! is_string($this->apiToken) || trim($this->apiToken) === '') {
            throw new GatewayApiException(
                message: 'Cloudflare API token is not configured.',
                errorCode: 'cloudflare_unavailable',
                errorMeta: ['reason' => 'token_missing'],
            );
        }

        $request = Http::acceptJson()->timeout(30);

        if (is_string($this->apiEmail) && trim($this->apiEmail) !== '') {
            return $request->withHeaders([
                'X-Auth-Email' => trim($this->apiEmail),
                'X-Auth-Key' => trim($this->apiToken),
            ]);
        }

        return $request->withToken(trim($this->apiToken));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function providerMessage(array $body): ?string
    {
        $errors = $body['errors'] ?? [];

        if (! is_array($errors) || $errors === []) {
            return null;
        }

        $first = reset($errors);

        if (! is_array($first)) {
            return null;
        }

        return is_string($first['message'] ?? null) ? $first['message'] : null;
    }
}
