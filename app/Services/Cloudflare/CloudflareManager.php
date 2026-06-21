<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

use App\Http\Gateway\GatewayApiException;
use App\Models\App;

final readonly class CloudflareManager
{
    public function __construct(private CloudflareClientFactory $clients) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function listZones(): array
    {
        $zones = collect($this->client()->zones())
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'data' => ['zones' => $zones],
            'meta' => ['count' => count($zones)],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function listDnsRecords(string $zoneIdentifier): array
    {
        $client = $this->client();
        $zone = $this->resolver($client)->resolveZone($zoneIdentifier);
        $records = collect($client->dnsRecords($zone['id']))
            ->map(fn (array $record): array => $this->recordEntity($record, $zone['name'], 'observed'))
            ->all();

        return [
            'data' => ['records' => $records],
            'meta' => ['zone' => $zone['name'], 'count' => count($records)],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function addDnsRecord(string $name, string $content, string $type = 'A', ?string $zoneIdentifier = null, bool $proxied = false): array
    {
        $type = strtoupper(trim($type));
        $this->validateAddressRecord($name, $content, $type);

        $client = $this->client();
        $resolver = $this->resolver($client);
        $zone = $zoneIdentifier !== null && trim($zoneIdentifier) !== ''
            ? $resolver->resolveZone($zoneIdentifier)
            : $resolver->resolveZoneForRecordName($name);

        $existingRecords = $client->dnsRecords($zone['id'], $name, $type);

        foreach ($existingRecords as $existingRecord) {
            if ($existingRecord['content'] === $content && $existingRecord['proxied'] === $proxied) {
                return [
                    'data' => ['record' => $this->recordEntity($existingRecord, $zone['name'], 'observed')],
                    'meta' => ['action' => 'create', 'already_present' => true],
                ];
            }

            throw new GatewayApiException(
                message: 'A conflicting Cloudflare DNS record already exists.',
                errorCode: 'validation_failed',
                errorMeta: [
                    'field' => 'name',
                    'zone' => $zone['name'],
                    'record_id' => $existingRecord['id'],
                    'conflict' => 'different_content',
                ],
            );
        }

        $record = $client->addDnsRecord($zone['id'], $name, $content, $type, $proxied);

        return [
            'data' => ['record' => $this->recordEntity($record, $zone['name'], 'created')],
            'meta' => ['action' => 'create', 'already_present' => false],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function removeDnsRecord(string $recordId, string $zoneIdentifier): array
    {
        $recordId = trim($recordId);

        if ($recordId === '') {
            throw new GatewayApiException(
                message: 'A Cloudflare DNS record ID is required.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'record_id'],
            );
        }

        $client = $this->client();
        $zone = $this->resolver($client)->resolveZone($zoneIdentifier);
        $record = $client->dnsRecord($zone['id'], $recordId);

        if (! in_array($record['type'], ['A', 'AAAA'], true)) {
            throw new GatewayApiException(
                message: 'Only Cloudflare address records can be removed.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'record_id', 'record_id' => $recordId, 'type' => $record['type']],
            );
        }

        $client->removeDnsRecord($zone['id'], $recordId);

        return [
            'data' => ['record' => $this->recordEntity($record, $zone['name'], 'removed')],
            'meta' => ['action' => 'remove'],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function flushCache(string $zoneIdentifier): array
    {
        $client = $this->client();
        $resolved = $this->resolver($client)->resolveZoneOrApp($zoneIdentifier);
        $zone = $resolved['zone'];
        $app = $resolved['app'];

        $client->flushCache($zone['id']);

        return [
            'data' => [
                'cache' => [
                    'zone' => $zone['name'],
                    'app' => $app instanceof App ? $app->name : null,
                    'action' => 'flush',
                    'status' => 'flushed',
                ],
            ],
            'meta' => ['provider' => 'cloudflare'],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function addCacheRule(string $appName): array
    {
        $client = $this->client();
        $resolved = $this->resolver($client)->resolveAppZone($appName);
        $alreadyPresent = $client->createCacheRule($resolved['zone']['id']);

        return [
            'data' => [
                'rule' => [
                    'app' => $resolved['app']->name,
                    'zone' => $resolved['zone']['name'],
                    'action' => 'add',
                    'cache' => true,
                    'browser_ttl' => 'respect_origin',
                    'status' => 'ready',
                ],
            ],
            'meta' => [
                'provider' => 'cloudflare',
                'already_present' => $alreadyPresent,
            ],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function removeCacheRule(string $appName): array
    {
        $client = $this->client();
        $resolved = $this->resolver($client)->resolveAppZone($appName);

        if (! $client->removeCacheRule($resolved['zone']['id'])) {
            throw new GatewayApiException(
                message: 'The app has no Cloudflare cache rule to remove.',
                errorCode: 'validation_failed',
                errorMeta: [
                    'field' => 'app',
                    'app' => $resolved['app']->name,
                    'zone' => $resolved['zone']['name'],
                    'reason' => 'cache_rule_missing',
                ],
            );
        }

        return [
            'data' => [
                'rule' => [
                    'app' => $resolved['app']->name,
                    'zone' => $resolved['zone']['name'],
                    'action' => 'remove',
                    'status' => 'removed',
                ],
            ],
            'meta' => ['provider' => 'cloudflare'],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function enableSsl(string $zoneIdentifier, string $mode = 'strict'): array
    {
        $mode = strtolower(trim($mode));

        if (! in_array($mode, ['strict', 'full'], true)) {
            throw new GatewayApiException(
                message: 'The selected Cloudflare SSL mode is invalid.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'mode', 'allowed' => ['strict', 'full']],
            );
        }

        return $this->setSsl($zoneIdentifier, $mode, 'enable');
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function disableSsl(string $zoneIdentifier): array
    {
        return $this->setSsl($zoneIdentifier, 'off', 'disable');
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function setSsl(string $zoneIdentifier, string $mode, string $action): array
    {
        $client = $this->client();
        $zone = $this->resolver($client)->resolveZone($zoneIdentifier);

        $client->setSslMode($zone['id'], $mode);

        return [
            'data' => [
                'ssl' => [
                    'zone' => $zone['name'],
                    'mode' => $mode,
                    'action' => $action,
                    'status' => 'set',
                ],
            ],
            'meta' => ['provider' => 'cloudflare'],
        ];
    }

    private function validateAddressRecord(string $name, string $content, string $type): void
    {
        if (trim($name) === '') {
            throw new GatewayApiException('A DNS record name is required.', 'validation_failed', ['field' => 'name']);
        }

        if (! in_array($type, ['A', 'AAAA'], true)) {
            throw new GatewayApiException(
                message: 'Cloudflare DNS writes are limited to A and AAAA records.',
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'type', 'allowed' => ['A', 'AAAA']],
            );
        }

        $ipFlag = $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;

        if (filter_var($content, FILTER_VALIDATE_IP, $ipFlag) === false) {
            throw new GatewayApiException(
                message: "The DNS record content must be a valid {$type} address.",
                errorCode: 'validation_failed',
                errorMeta: ['field' => 'content', 'type' => $type],
            );
        }
    }

    /**
     * @param  array{id: string, type: string, name: string, content: string, proxied: bool}  $record
     * @return array{id: string, zone: string, type: string, name: string, content: string, proxied: bool, status: string}
     */
    private function recordEntity(array $record, string $zone, string $status): array
    {
        return [
            'id' => $record['id'],
            'zone' => $zone,
            'type' => $record['type'],
            'name' => $record['name'],
            'content' => $record['content'],
            'proxied' => $record['proxied'],
            'status' => $status,
        ];
    }

    private function client(): CloudflareClient
    {
        return $this->clients->make();
    }

    private function resolver(CloudflareClient $client): CloudflareZoneResolver
    {
        return new CloudflareZoneResolver($client);
    }
}
