<?php

declare(strict_types=1);

namespace App\Tools;

use App\Services\Php\PhpRuntimeCatalog;

final class PhpCliTool extends BaseTool
{
    /** Base URL for the static-php-cli bulk preset downloads. */
    public const string BULK_BASE_URL = 'https://dl.static-php.dev/static-php-cli/bulk';

    private const string CurlRetryFlags = '--retry 5 --retry-delay 2 --retry-all-errors';

    /** Install root for static PHP binaries on the host. */
    public const string INSTALL_ROOT = '/opt/orbit/php';

    /**
     * Pinned patch versions for each supported minor.
     *
     * @var array<string,string>
     */
    public const array PATCH_PINS = [
        '8.5' => '8.5.6',
        '8.4' => '8.4.21',
        '8.3' => '8.3.31',
    ];

    public function slug(): string
    {
        return 'php-cli';
    }

    #[\Override]
    public function category(): string
    {
        return 'runtime';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'update', 'safe-adopt'];
    }

    #[\Override]
    public function installScript(array $config = []): string
    {
        return $this->buildScript('install');
    }

    #[\Override]
    public function updateScript(array $config = []): string
    {
        return $this->buildScript('update');
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => self::INSTALL_ROOT.'/8.5/bin/php',
            'version_command' => self::INSTALL_ROOT.'/8.5/bin/php --version',
        ];
    }

    private function buildScript(string $context): string
    {
        $base = self::BULK_BASE_URL;
        $root = self::INSTALL_ROOT;

        $versionBlocks = [];

        foreach (PhpRuntimeCatalog::SUPPORTED as $minor) {
            $patch = self::PATCH_PINS[$minor];

            $versionBlocks[] = <<<BASH
    # --- PHP {$minor} ---
    sudo mkdir -p {$root}/{$minor}/bin
    curl -fsSL {$this->curlRetryFlags()} "{$base}/php-{$patch}-cli-\${OS}-\${ARCH}.tar.gz" -o /tmp/orbit-php-{$minor}.tar.gz
    sudo tar -xzf /tmp/orbit-php-{$minor}.tar.gz -C {$root}/{$minor}/bin
    sudo chmod +x {$root}/{$minor}/bin/php
    sudo ln -sf {$root}/{$minor}/bin/php /usr/local/bin/php{$minor}
    rm -f /tmp/orbit-php-{$minor}.tar.gz
BASH;
        }

        $blocks = implode("\n", $versionBlocks);

        $header = $context === 'install'
            ? '#!/usr/bin/env bash'."\n".'# orbit install php-cli'."\n".'set -e'
            : '#!/usr/bin/env bash'."\n".'# orbit update php-cli'."\n".'set -e';

        return <<<BASH
{$header}

# Detect OS
case "\$(uname -s)" in
    Linux)  OS=linux  ;;
    Darwin) OS=macos  ;;
    *)      echo "unsupported os" >&2; exit 1 ;;
esac

# Detect architecture
case "\$(uname -m)" in
    x86_64|amd64)   ARCH=x86_64   ;;
    aarch64|arm64)  ARCH=aarch64  ;;
    *)              echo "unsupported arch" >&2; exit 1 ;;
esac

{$blocks}

# Set php8.5 as the default php
sudo ln -sf {$root}/8.5/bin/php /usr/local/bin/php
BASH;
    }

    private function curlRetryFlags(): string
    {
        return self::CurlRetryFlags;
    }
}
