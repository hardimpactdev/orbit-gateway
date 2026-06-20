<?php

declare(strict_types=1);

it('defines a gateway image workflow for ghcr publishing and digest capture', function (): void {
    $workflow = file_get_contents(repo_path('.github/workflows/orbit-gateway-image.yml'));

    expect($workflow)
        ->toContain('name: Orbit Gateway Image')
        ->toContain('ghcr.io')
        ->toContain('hardimpactdev/orbit-gateway')
        ->toContain('docker/orbit-gateway/Dockerfile')
        ->toContain('docker buildx build')
        ->toContain('--metadata-file')
        ->toContain('containerimage.digest')
        ->toContain('orbit-gateway-image-metadata')
        ->not->toContain('orbit'.'-runtime');
});
