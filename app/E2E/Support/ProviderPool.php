<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class ProviderPool
{
    /**
     * @param  list<E2EProvider>  $providers
     */
    public function __construct(
        private array $providers,
    ) {}

    public static function fromEnvironment(?E2EConfig $config = null): self
    {
        $config ??= E2EConfig::fromEnvironment();

        return new self(array_map(
            fn (string $provider): E2EProvider => self::makeProvider($provider, $config),
            $config->providerNames,
        ));
    }

    public function select(E2EImage ...$images): ProviderSelection
    {
        $failures = [];

        foreach ($this->providers as $provider) {
            $availability = $provider->availability($images);

            if ($availability->available) {
                return new ProviderSelection($provider, "{$provider->name()}: {$availability->message}");
            }

            $failures[] = "{$provider->name()}: {$availability->message}";
        }

        return new ProviderSelection(null, implode('; ', $failures));
    }

    private static function makeProvider(string $provider, E2EConfig $config): E2EProvider
    {
        return match ($provider) {
            'incus' => new IncusProvider($config),
            default => throw new \InvalidArgumentException("Unknown E2E provider [{$provider}]."),
        };
    }
}
