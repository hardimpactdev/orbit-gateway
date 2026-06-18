<?php

declare(strict_types=1);

use App\Enums\Gateway\GatewayExposureMode;
use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmStackRenderer;

it('renders the orbit scheduler as a singleton Swarm service from the gateway image', function (): void {
    $yaml = (new GatewaySwarmStackRenderer)->render(
        GatewayImageReference::fromString('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
        GatewayExposureMode::RouterColocated,
    );

    expect($yaml)
        ->toContain('  '.GatewaySwarmStackRenderer::SchedulerService.':')
        ->toContain('    image: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"')
        ->toContain('    command: ["php", "artisan", "orbit-scheduler"]')
        ->toContain('      replicas: 1')
        ->toContain('        order: stop-first')
        ->toContain('orbit-scheduler')
        ->not->toContain('orbit'.'-runtime')
        ->not->toContain('supervisor')
        ->not->toContain('/etc/supervisor');
});
