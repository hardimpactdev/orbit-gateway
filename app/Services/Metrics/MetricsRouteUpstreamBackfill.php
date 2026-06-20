<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteRenderer;

class MetricsRouteUpstreamBackfill
{
    public function run(): void
    {
        $renderer = app(ProxyRouteRenderer::class);

        ProxyRoute::query()
            ->where('domain', MetricsServiceRoute::Domain)
            ->where('owner_type', 'router')
            ->where('kind', 'proxy')
            ->get()
            ->each(function (ProxyRoute $route) use ($renderer): void {
                $config = is_array($route->config) ? $route->config : [];

                if (($config['owner_name'] ?? null) !== MetricsServiceRoute::OwnerName) {
                    return;
                }

                $route->config = MetricsServiceRoute::config();
                $route->source_hash = $renderer->sourceHash($route);
                $route->save();
            });
    }
}
