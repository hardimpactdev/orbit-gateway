<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\Process;

final readonly class ProcessServiceMetadataPayload
{
    /**
     * @return array<string, mixed>|null
     */
    public function forProcess(Process $process): ?array
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $definition = $this->optionalString($config, 'definition');

        if ($definition === null) {
            return null;
        }

        return [
            'definition' => $definition,
            'version_family' => $this->optionalString($config, 'version_family'),
            'version' => $this->optionalString($config, 'version'),
            'service_name' => $this->optionalString($config, 'service_name'),
            'endpoint' => $this->endpoint($config['endpoint'] ?? null),
            'endpoints' => $this->endpoints($config),
            'credential_fields' => $this->credentialFields($config['credentials'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{name: string|null, host: string, port: int|null}>
     */
    private function endpoints(array $config): array
    {
        $rawEndpoints = [];

        if (is_array($config['endpoint'] ?? null)) {
            $rawEndpoints[] = $config['endpoint'];
        }

        if (is_array($config['endpoints'] ?? null)) {
            foreach ($config['endpoints'] as $endpoint) {
                if (is_array($endpoint)) {
                    $rawEndpoints[] = $endpoint;
                }
            }
        }

        $endpoints = [];
        $seen = [];

        foreach ($rawEndpoints as $rawEndpoint) {
            $endpoint = $this->endpoint($rawEndpoint);

            if ($endpoint === null) {
                continue;
            }

            $key = implode(':', [
                $endpoint['name'] ?? '',
                $endpoint['host'],
                (string) ($endpoint['port'] ?? ''),
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $endpoints[] = $endpoint;
        }

        return $endpoints;
    }

    /**
     * @return array{name: string|null, host: string, port: int|null}|null
     */
    private function endpoint(mixed $endpoint): ?array
    {
        if (! is_array($endpoint)) {
            return null;
        }

        $host = is_string($endpoint['host'] ?? null) ? trim($endpoint['host']) : '';

        if ($host === '') {
            return null;
        }

        $name = is_string($endpoint['name'] ?? null) ? trim($endpoint['name']) : null;
        $port = is_numeric($endpoint['port'] ?? null) ? (int) $endpoint['port'] : null;

        return [
            'name' => $name !== '' ? $name : null,
            'host' => $host,
            'port' => $port,
        ];
    }

    /**
     * @return list<string>
     */
    private function credentialFields(mixed $credentials): array
    {
        if (! is_array($credentials)) {
            return [];
        }

        $fields = [];

        foreach (array_keys($credentials) as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $fields[] = $key;
        }

        sort($fields);

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
