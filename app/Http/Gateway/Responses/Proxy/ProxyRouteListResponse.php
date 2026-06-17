<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Proxy;

final readonly class ProxyRouteListResponse
{
    /**
     * @param  list<array<string, mixed>>  $routes
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $routes,
        public array $meta,
    ) {}
}
