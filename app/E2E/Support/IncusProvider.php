<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Throwable;

final class IncusProvider implements E2EProvider
{
    public IncusHost $host;

    private ?E2EResourceLease $resourceLease = null;

    public function __construct(
        private E2EConfig $config,
    ) {
        $this->host = new IncusHost($config);
    }

    public function __destruct()
    {
        $this->releaseResourceLease();
    }

    public function name(): string
    {
        return 'incus';
    }

    public function config(): E2EConfig
    {
        return $this->config;
    }

    /**
     * @param  list<E2EImage>  $images
     */
    public function availability(array $images): ProviderAvailability
    {
        $this->ensureResourceLease();

        $availability = $this->inspectAvailability($images);

        if (! $availability->available) {
            $this->releaseResourceLease();
        }

        return $availability;
    }

    /**
     * @param  list<E2EImage>  $images
     */
    private function inspectAvailability(array $images): ProviderAvailability
    {
        if (! $this->host->commandExists('incus')) {
            return ProviderAvailability::unavailable('incus command is not available on the E2E host');
        }

        if (! $this->host->run('incus info')->successful()) {
            return ProviderAvailability::unavailable('incus info failed on the E2E host');
        }

        if (! $this->host->run('incus network show incusbr0')->successful()) {
            return ProviderAvailability::unavailable('incusbr0 network is not available on the E2E host');
        }

        foreach ($images as $image) {
            try {
                $alias = $this->aliasFor($image);
            } catch (\RuntimeException $exception) {
                return ProviderAvailability::unavailable($exception->getMessage());
            }

            if (! $this->host->imageExists($alias)) {
                return ProviderAvailability::unavailable("incus image {$alias} is not available");
            }
        }

        return ProviderAvailability::available('incus is available');
    }

    public function startRun(string $label): E2ERun
    {
        $this->ensureResourceLease();

        $safeLabel = E2ERun::safeLabel($label);
        $id = E2ERun::id();
        $remoteDirectory = "/tmp/{$this->config->instancePrefix}-{$id}-{$safeLabel}";

        $result = $this->host->run(sprintf(
            'rm -rf %s && mkdir -p %s',
            escapeshellarg($remoteDirectory),
            escapeshellarg($remoteDirectory),
        ));

        if (! $result->successful()) {
            $this->releaseResourceLease();

            throw new \RuntimeException("Could not create remote E2E run directory: {$result->errorOutput()}");
        }

        return new E2ERun($this, $id, $safeLabel, $remoteDirectory);
    }

    public function createSshKeyPair(E2ERun $run): SshKeyPair
    {
        $privateKeyPath = "{$run->workDirectory}/id_ed25519";
        $publicKeyPath = "{$privateKeyPath}.pub";

        $result = $this->host->run(sprintf(
            'ssh-keygen -t ed25519 -N %s -f %s -C %s >/dev/null',
            escapeshellarg(''),
            escapeshellarg($privateKeyPath),
            escapeshellarg("orbit-e2e-{$run->id}"),
        ));

        if (! $result->successful()) {
            throw new \RuntimeException("Could not create E2E SSH key pair: {$result->errorOutput()}");
        }

        return new SshKeyPair($privateKeyPath, $publicKeyPath);
    }

    public function launch(E2ERun $run, E2EImage $image, string $suffix): E2EInstance
    {
        $instance = new IncusInstance(
            host: $this->host,
            name: "{$this->config->instancePrefix}-{$run->id}-".E2ERun::safeLabel($suffix),
        );

        $result = $this->host->launchInstance(
            image: $this->aliasFor($image),
            name: $instance->name(),
            config: sprintf(
                '--config=limits.cpu=%s --config=limits.memory=%s',
                escapeshellarg($this->config->cpus),
                escapeshellarg($this->config->memory),
            ),
        );

        if (! $result->successful()) {
            throw new \RuntimeException("Could not launch {$instance->name()}: {$result->errorOutput()}");
        }

        $instance->waitForAgent();

        return $instance;
    }

    /**
     * @param  list<E2EInstance>  $instances
     */
    public function cleanup(E2ERun $run, array $instances): void
    {
        try {
            if ($this->config->keep) {
                fwrite(STDERR, "ORBIT_E2E_KEEP=1; keeping E2E run {$run->id} on {$this->config->host}\n");

                return;
            }

            try {
                foreach ($instances as $instance) {
                    try {
                        $instance->delete();
                    } catch (Throwable $exception) {
                        fwrite(STDERR, "Could not delete E2E instance {$instance->name()}: {$exception->getMessage()}\n");
                    }
                }

                $this->host->run(sprintf('rm -rf %s', escapeshellarg($run->workDirectory)), timeoutSeconds: 120);
            } catch (Throwable $exception) {
                fwrite(STDERR, "Could not remove E2E run directory {$run->workDirectory}: {$exception->getMessage()}\n");
            }
        } finally {
            $this->releaseResourceLease();
        }
    }

    private function aliasFor(E2EImage $image): string
    {
        return match ($image) {
            E2EImage::Base => $this->config->baseImage,
        };
    }

    private function ensureResourceLease(): void
    {
        if ($this->resourceLease !== null || $this->config->incusHostSlots === []) {
            return;
        }

        $this->resourceLease = E2EResourceLeasePool::fromEnvironment(
            waitSeconds: $this->config->slotWaitSeconds,
            staleSeconds: $this->config->slotStaleSeconds,
        )->acquire('incus', $this->config->incusHostSlots, $this->config->exclusiveHosts);

        $this->config = $this->config->forHost($this->resourceLease->host());
        $this->host = new IncusHost($this->config);
    }

    private function releaseResourceLease(): void
    {
        $this->resourceLease?->release();
        $this->resourceLease = null;
    }
}
