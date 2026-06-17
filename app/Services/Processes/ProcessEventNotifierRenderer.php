<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\LocalGatewaySettings;
use RuntimeException;

final readonly class ProcessEventNotifierRenderer
{
    public function scriptPath(): string
    {
        return repo_path('apps/gateway/resources/node-scripts/orbit-notify-exit.sh');
    }

    public function installPath(): string
    {
        return '/usr/local/bin/orbit-notify-exit';
    }

    public function gatewayEndpointPath(): string
    {
        return '/etc/orbit/gateway-endpoint';
    }

    public function content(): string
    {
        $content = file_get_contents($this->scriptPath());

        if ($content === false) {
            throw new RuntimeException("Cannot read process event notifier script: {$this->scriptPath()}");
        }

        return $content;
    }

    public function hash(): string
    {
        return hash('sha256', $this->content());
    }

    public function expectedGatewayEndpoint(): ?string
    {
        $gatewayUrl = LocalGatewaySettings::current()->gateway_url;

        if (! is_string($gatewayUrl) || trim($gatewayUrl) === '') {
            return null;
        }

        return rtrim($gatewayUrl, '/');
    }
}
