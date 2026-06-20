<?php

declare(strict_types=1);

namespace App\Services\Php;

final readonly class PhpRuntimePolicy
{
    public const string MODE_CLASSIC = 'classic';

    public function __construct(
        private PhpRuntimeCatalog $catalog,
    ) {}

    public function forVersion(string $version, ?string $preloadPath = null): PhpRuntimePolicyConfig
    {
        $phpIni = [
            'opcache.enable' => '1',
            'opcache.enable_cli' => '1',
            'opcache.memory_consumption' => '256',
            'opcache.max_accelerated_files' => '20000',
            'realpath_cache_size' => '4096K',
            'realpath_cache_ttl' => '600',
        ];

        if (is_string($preloadPath) && trim($preloadPath) !== '') {
            $phpIni['opcache.preload'] = trim($preloadPath);
        }

        return new PhpRuntimePolicyConfig(
            phpVersion: trim($version),
            image: $this->catalog->imageFor($version),
            mode: self::MODE_CLASSIC,
            phpIni: $phpIni,
        );
    }
}
