<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use App\Http\Gateway\Plugins\HasCorrelationHeader;
use App\Models\LocalGatewaySettings;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

final class GatewayConnector extends Connector
{
    use AlwaysThrowOnErrors;
    use HasCorrelationHeader;

    public function __construct(
        private readonly string $clientName = 'cli',
        private readonly ?string $baseUrl = null,
        private readonly string|bool|null $caPemPath = null,
        private readonly int $timeout = 900,
    ) {}

    public static function forScheduler(): self
    {
        return new self('scheduler');
    }

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl ?? LocalGatewaySettings::current()->gateway_url ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Orbit-Client' => $this->orbitClientName(),
        ];
    }

    protected function orbitClientName(): string
    {
        return $this->clientName;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'verify' => $this->caPemPath ?? LocalGatewaySettings::current()->ca_pem_path,
            'allow_redirects' => false,
            'timeout' => $this->timeout,
            'connect_timeout' => 10,
        ];
    }
}
