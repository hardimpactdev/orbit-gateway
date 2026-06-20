<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\AppInstance;
use App\Services\Php\PhpRuntimeCatalog;

final readonly class LaravelCloudRuntimeCompatibility
{
    private const array KNOWN_EXTENSION_SUPPORT = [
        'bcmath',
        'ctype',
        'curl',
        'dom',
        'fileinfo',
        'filter',
        'gd',
        'iconv',
        'intl',
        'mbstring',
        'openssl',
        'pcntl',
        'pdo',
        'pdo_mysql',
        'pdo_pgsql',
        'redis',
        'sodium',
        'tokenizer',
        'xml',
        'zip',
    ];

    public function __construct(
        private PhpRuntimeCatalog $phpRuntimeCatalog = new PhpRuntimeCatalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forInstance(AppInstance $instance): array
    {
        $instance->loadMissing('app');

        $phpVersion = (string) $instance->app->php_version;
        $versionSupported = $this->phpRuntimeCatalog->supports($phpVersion);
        $extensions = [];

        foreach ($instance->runtimeRequirements()->normalizedPhpExtensions() as $extension) {
            $supported = in_array($extension, self::KNOWN_EXTENSION_SUPPORT, true);
            $extensions[$extension] = [
                'supported' => $supported,
                'status' => $supported ? 'known_supported' : 'unknown',
            ];
        }

        $unsupported = array_filter($extensions, static fn (array $entry): bool => $entry['supported'] !== true);

        return [
            'target' => 'laravel-cloud',
            'compatible' => $versionSupported && $unsupported === [],
            'php_version' => [
                'version' => $phpVersion,
                'supported' => $versionSupported,
            ],
            'extensions' => $extensions,
        ];
    }
}
