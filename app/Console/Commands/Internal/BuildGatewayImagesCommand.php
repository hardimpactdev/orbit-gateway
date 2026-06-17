<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Services\Runtime\OrbitCaddyContainer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Make sure the local Docker daemon has the images the Orbit gateway
 * contract depends on: `orbit-gateway:current` (built from the Orbit
 * source) and the official upstream Caddy image used by orbit-caddy.
 *
 * `docker run --pull never` is what the tool reconcile loop uses, so the
 * Docker daemon must already have both images locally before any
 * orbit-gateway / orbit-caddy container is created on a real node. The
 * installer (`bin/install-orbit`) and the gateway bootstrap call this
 * command so production nodes get a real, non-E2E setup path that
 * matches the Docker-first gateway service contract.
 *
 * The Caddy image is pulled rather than rebuilt because Orbit does not
 * maintain a bespoke Caddy build — orbit-caddy's complete config is host
 * bind-mounted when the container runs.
 */
#[Signature('orbit:internal:build-gateway-images
    {--force : Rebuild orbit-gateway and re-pull the Caddy image even when both already exist locally}')]
#[Description('Ensure the orbit-gateway image is built and the orbit-caddy Caddy image is pulled locally')]
class BuildGatewayImagesCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (! $this->ensureOrbitGatewayImage($force)) {
            return self::FAILURE;
        }

        if (! $this->ensureCaddyImage($force)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function ensureOrbitGatewayImage(bool $force): bool
    {
        $image = 'orbit-gateway:current';

        if (! $force && $this->imageExistsLocally($image)) {
            $this->line("Skipping {$image} (already present locally).");

            return true;
        }

        $result = Process::timeout(1800)->run(sprintf(
            'docker build --pull -f %s -t %s %s',
            escapeshellarg(repo_path('docker/orbit-gateway/Dockerfile')),
            escapeshellarg($image),
            escapeshellarg(repo_path()),
        ));

        if (! $result->successful()) {
            $this->error(trim($result->output().$result->errorOutput()));

            return false;
        }

        $this->info("Built {$image}.");

        return true;
    }

    private function ensureCaddyImage(bool $force): bool
    {
        $image = OrbitCaddyContainer::Image;

        if (! $force && $this->imageExistsLocally($image)) {
            $this->line("Skipping {$image} (already present locally).");

            return true;
        }

        $result = Process::timeout(1800)->run(sprintf(
            'docker pull %s',
            escapeshellarg($image),
        ));

        if (! $result->successful()) {
            $this->error(trim($result->output().$result->errorOutput()));

            return false;
        }

        $this->info("Pulled {$image}.");

        return true;
    }

    private function imageExistsLocally(string $image): bool
    {
        return Process::run(sprintf(
            'docker image inspect %s >/dev/null 2>&1',
            escapeshellarg($image),
        ))->successful();
    }
}
