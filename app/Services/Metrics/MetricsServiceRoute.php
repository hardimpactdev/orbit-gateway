<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Services\Proxy\ProxyRouteRenderer;

class MetricsServiceRoute
{
    public const string Domain = 'metrics.orbit';

    public const string OwnerName = 'grafana';

    public const string Scheme = 'http';

    public const int Port = 3000;

    public static function upstreamHost(): string
    {
        return ProxyRouteRenderer::HostLoopbackHostname;
    }

    public static function upstreamUrl(): string
    {
        $scheme = self::Scheme;
        $host = self::upstreamHost();
        $port = self::Port;

        return "{$scheme}://{$host}:{$port}";
    }

    /**
     * @return array{
     *     owner_name: string,
     *     protocol: string,
     *     target: array{type: string, value: string},
     *     upstreams: array<int, array{scheme: string, host: string, port: int}>
     * }
     */
    public static function config(): array
    {
        return [
            'owner_name' => self::OwnerName,
            'protocol' => self::Scheme,
            'target' => [
                'type' => 'upstream',
                'value' => self::upstreamUrl(),
            ],
            'upstreams' => [
                ['scheme' => self::Scheme, 'host' => self::upstreamHost(), 'port' => self::Port],
            ],
        ];
    }
}
