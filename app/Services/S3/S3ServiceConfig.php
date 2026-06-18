<?php

declare(strict_types=1);

namespace App\Services\S3;

final readonly class S3ServiceConfig
{
    public const string ServiceEndpoint = 'https://s3.orbit';

    public const string ServiceHost = 's3.orbit';

    public const string Region = 'orbit';

    /**
     * @param  list<string>  $publicHosts  Public HTTPS hostnames published on ingress (e.g. ['s3.example.com']).
     */
    public function __construct(
        public string $nodeName,
        public string $wireguardAddress,
        public string $dataPath,
        public string $accessKeyId,
        public string $secretAccessKey,
        public array $publicHosts = [],
    ) {}

    /**
     * The backend bind address — WireGuard address:8333 — used for the
     * SeaweedFS container port binding and router backend pool entries.
     */
    public function backendBind(): string
    {
        return "{$this->wireguardAddress}:8333";
    }

    /**
     * The stable private S3 service endpoint.
     */
    public function serviceEndpoint(): string
    {
        return self::ServiceEndpoint;
    }
}
