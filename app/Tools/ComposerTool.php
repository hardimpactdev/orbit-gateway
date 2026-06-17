<?php

declare(strict_types=1);

namespace App\Tools;

final class ComposerTool extends BaseTool
{
    public function slug(): string
    {
        return 'composer';
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
        return <<<'BASH'
#!/usr/bin/env bash
# orbit install composer
set -e

cd /tmp

# Fetch the official installer signature
EXPECTED_SIG="$(curl -fsSL https://composer.github.io/installer.sig)"

# Download the installer
curl -fsSL https://getcomposer.org/installer -o composer-setup.php

# Verify the SHA-384 hash
ACTUAL_SIG="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

if [ "$EXPECTED_SIG" != "$ACTUAL_SIG" ]; then
    echo "ERROR: Composer installer signature verification failed." >&2
    rm -f composer-setup.php
    exit 1
fi

# Install Composer to /usr/local/bin
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer

rm -f composer-setup.php
BASH;
    }

    public function updateScript(array $config = []): string
    {
        return 'sudo /usr/local/bin/composer self-update 2>/dev/null';
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => '/usr/local/bin/composer',
            'version_command' => '/usr/local/bin/composer --version',
            'update_command' => $this->updateScript(),
        ];
    }
}
