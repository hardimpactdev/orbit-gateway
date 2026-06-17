<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Processes\ProcessRuntime;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Nodes\NodeWireGuardServiceAddress;
use RuntimeException;

final readonly class ProcessServiceDefinitionRegistry
{
    public function __construct(
        private NodeWireGuardServiceAddress $serviceAddress,
    ) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->definitions());
    }

    public function supports(string $definition): bool
    {
        return array_key_exists($definition, $this->definitions());
    }

    public function resolve(string $definition, ?string $version, ProcessRuntime $runtime, Node $node, string $processName): ProcessServiceDefinition
    {
        $catalog = $this->definitions();
        $service = $catalog[$definition] ?? null;

        if (! is_array($service)) {
            throw new GatewayApiException("Process definition '{$definition}' is not supported.", 'validation_failed', [
                'field' => 'definition',
                'value' => $definition,
                'reason' => 'unsupported_value',
                'allowed' => $this->names(),
            ]);
        }

        $allowedRuntimes = $this->allowedRuntimes($service);

        if (! in_array($runtime, $allowedRuntimes, true)) {
            throw new GatewayApiException("Process definition '{$definition}' does not support runtime '{$runtime->value}'.", 'validation_failed', [
                'field' => 'runtime',
                'value' => $runtime->value,
                'reason' => 'process_definition_runtime_unsupported',
                'definition' => $definition,
                'allowed' => array_map(static fn (ProcessRuntime $runtime): string => $runtime->value, $allowedRuntimes),
            ]);
        }

        $resolved = $this->resolveVersion($definition, $service['versions'], $version);
        $host = $this->serviceHost($node);
        $serviceName = "orbit-{$processName}";
        $volumeName = "orbit-{$processName}";
        $dataPath = "/var/lib/orbit/processes/{$processName}";

        $runtimeConfig = [
            'definition' => $definition,
            'version_family' => $resolved['family'],
            'version' => $resolved['version'],
            'endpoint' => [
                'name' => $processName,
                'kind' => 'tcp',
                'host' => $host,
                'port' => $resolved['published_port'],
            ],
            'endpoints' => [
                [
                    'name' => $processName,
                    'kind' => 'tcp',
                    'host' => $host,
                    'port' => $resolved['published_port'],
                ],
            ],
            'service_name' => $serviceName,
            'environment' => $service['environment'],
            'network_aliases' => array_values(array_unique([$definition, $processName])),
            'healthcheck' => $service['healthcheck'],
            'credentials' => $service['credentials'],
            'update_strategy' => [
                'order' => 'stop-first',
                'parallelism' => 1,
            ],
        ];

        if (is_string($service['image'] ?? null) && $service['image'] !== '') {
            $runtimeConfig['image'] = "{$service['image']}:{$resolved['version']}";
        }

        if (is_int($service['target_port'] ?? null)) {
            $runtimeConfig['ports'] = [
                [
                    'published' => $resolved['published_port'],
                    'target' => $service['target_port'],
                    'protocol' => 'tcp',
                ],
            ];
        }

        if (is_string($service['data_path'] ?? null) && $service['data_path'] !== '') {
            $runtimeConfig['mounts'] = [
                [
                    'source' => $dataPath,
                    'target' => $service['data_path'],
                ],
            ];
            $runtimeConfig['volumes'] = [
                [
                    'name' => $volumeName,
                    'target' => $service['data_path'],
                ],
            ];
        }

        $specHash = $this->specHash([
            ...$runtimeConfig,
            'runtime' => $runtime->value,
            'process' => $processName,
        ]);

        $runtimeConfig['spec_hash'] = $specHash;
        $runtimeConfig['labels'] = [
            'orbit.managed' => 'true',
            'orbit.process' => $processName,
            'orbit.process.definition' => $definition,
            'orbit.process.version_family' => $resolved['family'],
            'orbit.process.version' => $resolved['version'],
            'orbit.process.spec_hash' => $specHash,
        ];

        return new ProcessServiceDefinition(
            name: $definition,
            versionFamily: $resolved['family'],
            version: $resolved['version'],
            command: $service['command'],
            runtimeConfig: $runtimeConfig,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            'mysql' => [
                'runtimes' => [ProcessRuntime::Docker, ProcessRuntime::DockerSwarm],
                'image' => 'mysql',
                'command' => 'mysqld',
                'target_port' => 3306,
                'data_path' => '/var/lib/mysql',
                'environment' => [
                    'MYSQL_DATABASE' => 'orbit',
                    'MYSQL_PASSWORD' => 'orbit',
                    'MYSQL_ROOT_PASSWORD' => 'orbit',
                    'MYSQL_USER' => 'orbit',
                ],
                'credentials' => [
                    'database' => 'orbit',
                    'password' => 'orbit',
                    'username' => 'orbit',
                ],
                'healthcheck' => [
                    'command' => 'mysqladmin ping -horbit -porbit',
                    'kind' => 'command',
                ],
                'versions' => [
                    '8' => [
                        'default' => '8.4',
                        'versions' => ['8.4'],
                        'port' => 3308,
                    ],
                    '9' => [
                        'default' => '9',
                        'versions' => ['9'],
                        'port' => 3309,
                    ],
                ],
            ],
            'redis' => [
                'runtimes' => [ProcessRuntime::Docker, ProcessRuntime::DockerSwarm],
                'image' => 'redis',
                'command' => 'redis-server --appendonly yes --bind 0.0.0.0 --protected-mode no',
                'target_port' => 6379,
                'data_path' => '/data',
                'environment' => [],
                'credentials' => [],
                'healthcheck' => [
                    'command' => 'redis-cli ping',
                    'kind' => 'command',
                ],
                'versions' => [
                    '7' => [
                        'default' => '7.2',
                        'versions' => ['7.2'],
                        'port' => 6379,
                    ],
                ],
            ],
            'prometheus' => [
                'runtimes' => [ProcessRuntime::DockerSwarm],
                'image' => 'prom/prometheus',
                'command' => 'prometheus --config.file=/etc/prometheus/prometheus.yml --storage.tsdb.path=/prometheus --storage.tsdb.retention.time=15d --web.listen-address=0.0.0.0:9090',
                'target_port' => 9090,
                'data_path' => '/prometheus',
                'environment' => [],
                'credentials' => [],
                'healthcheck' => [
                    'command' => 'wget -qO- http://127.0.0.1:9090/-/ready >/dev/null',
                    'kind' => 'command',
                ],
                'versions' => [
                    '3' => [
                        'default' => 'v3.12.0',
                        'versions' => ['v3.12.0'],
                        'port' => 9090,
                    ],
                ],
            ],
            'grafana' => [
                'runtimes' => [ProcessRuntime::DockerSwarm],
                'image' => 'grafana/grafana',
                'command' => '/run.sh',
                'target_port' => 3000,
                'data_path' => '/var/lib/grafana',
                'environment' => [
                    'GF_SECURITY_ADMIN_USER' => 'admin',
                    'GF_SERVER_ROOT_URL' => 'https://metrics.orbit',
                ],
                'credentials' => [
                    'admin_user' => 'admin',
                ],
                'healthcheck' => [
                    'command' => 'wget -qO- http://127.0.0.1:3000/api/health >/dev/null',
                    'kind' => 'command',
                ],
                'versions' => [
                    '13' => [
                        'default' => '13.0.2',
                        'versions' => ['13.0.2'],
                        'port' => 3000,
                    ],
                ],
            ],
            'node-exporter' => [
                'runtimes' => [ProcessRuntime::Systemd],
                'command' => '/usr/local/bin/node_exporter --web.listen-address=0.0.0.0:9100',
                'environment' => [],
                'credentials' => [],
                'healthcheck' => [
                    'command' => 'curl -fsS http://127.0.0.1:9100/metrics >/dev/null',
                    'kind' => 'command',
                ],
                'versions' => [
                    '1' => [
                        'default' => '1.11.1',
                        'versions' => ['1.11.1'],
                        'port' => 9100,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $service
     * @return list<ProcessRuntime>
     */
    private function allowedRuntimes(array $service): array
    {
        $runtimes = $service['runtimes'] ?? null;

        if (! is_array($runtimes) || $runtimes === []) {
            return [ProcessRuntime::Docker, ProcessRuntime::DockerSwarm];
        }

        return array_values(array_filter(
            $runtimes,
            static fn (mixed $runtime): bool => $runtime instanceof ProcessRuntime,
        ));
    }

    /**
     * @param  array<array-key, array{default: string, versions: list<string>, port: int}>  $versions
     * @return array{family: string, version: string, published_port: int}
     */
    private function resolveVersion(string $definition, array $versions, ?string $version): array
    {
        if ($version === null && count($versions) > 1) {
            throw new GatewayApiException("Process definition '{$definition}' requires a version.", 'validation_failed', [
                'field' => 'version',
                'reason' => 'required',
                'definition' => $definition,
                'allowed' => $this->versionFamilies($versions),
            ]);
        }

        if ($version === null) {
            $familyKey = array_key_first($versions);
            $family = (string) $familyKey;
            $metadata = $versions[$familyKey];

            return [
                'family' => $family,
                'version' => $metadata['default'],
                'published_port' => $metadata['port'],
            ];
        }

        foreach ($versions as $familyKey => $metadata) {
            $family = (string) $familyKey;

            if ($version === $family || $version === $metadata['default'] || in_array($version, $metadata['versions'], true)) {
                return [
                    'family' => $family,
                    'version' => $version === $family ? $metadata['default'] : $version,
                    'published_port' => $metadata['port'],
                ];
            }
        }

        throw new GatewayApiException("Process definition '{$definition}' does not support version '{$version}'.", 'validation_failed', [
            'field' => 'version',
            'value' => $version,
            'reason' => 'unsupported_value',
            'definition' => $definition,
            'allowed' => $this->versionFamilies($versions),
        ]);
    }

    /**
     * @param  array<array-key, array{default: string, versions: list<string>, port: int}>  $versions
     * @return list<string>
     */
    private function versionFamilies(array $versions): array
    {
        return array_map(
            static fn (int|string $family): string => (string) $family,
            array_keys($versions),
        );
    }

    private function serviceHost(Node $node): string
    {
        try {
            return $this->serviceAddress->forServiceOn($node, $node, 'process');
        } catch (RuntimeException) {
            throw new GatewayApiException("Node '{$node->name}' cannot host service process endpoints without a WireGuard address.", 'validation_failed', [
                'field' => 'node',
                'value' => $node->name,
                'reason' => 'wireguard_address_required',
            ]);
        }

    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function specHash(array $spec): string
    {
        ksort($spec);

        return substr(hash('sha256', json_encode($spec, JSON_THROW_ON_ERROR)), 0, 16);
    }
}
