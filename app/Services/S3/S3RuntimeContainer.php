<?php

declare(strict_types=1);

namespace App\Services\S3;

use InvalidArgumentException;

class S3RuntimeContainer
{
    public const string ContainerName = 'orbit-seaweedfs';

    public const string Image = 'chrislusf/seaweedfs:4.33';

    public const string DataTarget = '/data';

    public const string S3ConfigTarget = '/etc/seaweedfs/s3.json';

    public const string DefaultDataPath = '/srv/orbit/s3/data';

    public const string SpecHashLabel = 'orbit.s3.spec_hash';

    public const int ApiPort = 8333;

    /** @var list<array{source: string, target: string, read_only: bool}> */
    private readonly array $mounts;

    /**
     * @param  list<array{source: string, target: string, read_only?: bool}>  $mounts
     * @param  array<string, mixed>  $s3Config
     */
    public function __construct(
        private readonly string $name,
        private readonly string $image,
        private readonly string $network,
        private readonly string $restartPolicy,
        private readonly string $wireGuardAddress,
        array $mounts,
        private readonly array $s3Config,
    ) {
        $this->mounts = $this->normalizeMounts($mounts);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function image(): string
    {
        return $this->image;
    }

    public function network(): string
    {
        return $this->network;
    }

    public function restartPolicy(): string
    {
        return $this->restartPolicy;
    }

    public function wireGuardAddress(): string
    {
        return $this->wireGuardAddress;
    }

    /** @return list<array{source: string, target: string, read_only: bool}> */
    public function mounts(): array
    {
        return $this->mounts;
    }

    public function command(): string
    {
        return 'weed server -filer -s3 -s3.port='.self::ApiPort.' -s3.config='.self::S3ConfigTarget;
    }

    /** @return list<string> */
    public function publishedPorts(): array
    {
        return [
            "{$this->wireGuardAddress}:".self::ApiPort.':'.self::ApiPort,
        ];
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function s3Config(): array
    {
        return $this->s3Config;
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        $labels = [
            'orbit.managed' => 'true',
            'orbit.container.kind' => 's3-runtime',
            self::SpecHashLabel => $this->specHash(),
        ];

        ksort($labels);

        return $labels;
    }

    public function specHash(): string
    {
        return hash('sha256', json_encode($this->spec(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{
     *     name: string,
     *     image: string,
     *     network: string,
     *     restart_policy: string,
     *     wireguard_address: string,
     *     command: string,
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     published_ports: list<string>,
     *     s3_config: array<string, mixed>,
     * }
     */
    public function spec(): array
    {
        return [
            'name' => $this->name,
            'image' => $this->image,
            'network' => $this->network,
            'restart_policy' => $this->restartPolicy,
            'wireguard_address' => $this->wireGuardAddress,
            'command' => $this->command(),
            'mounts' => $this->mounts,
            'published_ports' => $this->publishedPorts(),
            's3_config' => $this->s3Config,
        ];
    }

    /**
     * @param  list<array{source: string, target: string, read_only?: bool}>  $mounts
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    private function normalizeMounts(array $mounts): array
    {
        return array_map(function (array $mount): array {
            $source = trim($mount['source']);
            $target = trim($mount['target']);

            if ($source === '' || $target === '') {
                throw new InvalidArgumentException('S3 runtime container mounts require source and target paths.');
            }

            return [
                'source' => $source,
                'target' => $target,
                'read_only' => (bool) ($mount['read_only'] ?? false),
            ];
        }, $mounts);
    }
}
