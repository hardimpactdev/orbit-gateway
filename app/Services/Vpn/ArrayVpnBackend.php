<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use App\Data\Vpn\VpnBackendClient;
use App\Data\Vpn\VpnPasswordRotationResult;
use RuntimeException;

final class ArrayVpnBackend implements VpnBackend
{
    public bool $listCalled = false;

    public ?string $changedPassword = null;

    /** @var array<string, VpnBackendClient> */
    private array $clients = [];

    /**
     * @param  list<VpnBackendClient>  $clients
     */
    public function __construct(array $clients = [])
    {
        foreach ($clients as $client) {
            $this->clients[$client->name] = $client;
        }
    }

    public function clients(?string $totp = null): array
    {
        $this->listCalled = true;

        return array_values($this->clients);
    }

    public function createClient(string $name, bool $includeConfig = false, ?string $totp = null): VpnBackendClient
    {
        if ($this->hasClient($name)) {
            throw new RuntimeException('VPN client name is already in use.');
        }

        $next = count($this->clients) + 7;
        $client = new VpnBackendClient(
            id: "client-{$next}",
            name: $name,
            address: "10.6.0.{$next}",
            enabled: true,
            latestHandshakeAt: null,
            config: $includeConfig ? "[Interface]\nPrivateKey = test\n" : null,
        );

        $this->clients[$name] = $client;

        return $client;
    }

    public function enableClient(string $name, ?string $totp = null): VpnBackendClient
    {
        return $this->setClientEnabled($name, true);
    }

    public function disableClient(string $name, ?string $totp = null): VpnBackendClient
    {
        return $this->setClientEnabled($name, false);
    }

    public function removeClient(string $name, ?string $totp = null): void
    {
        $this->requireClient($name);
        unset($this->clients[$name]);
    }

    public function changeWebUiPassword(string $password, ?string $totp = null): VpnPasswordRotationResult
    {
        $this->changedPassword = $password;

        return new VpnPasswordRotationResult(passwordChanged: true, sessionsInvalidated: true);
    }

    public function hasClient(string $name): bool
    {
        return array_key_exists($name, $this->clients);
    }

    private function requireClient(string $name): VpnBackendClient
    {
        return $this->clients[$name] ?? throw new RuntimeException('VPN client does not exist.');
    }

    private function setClientEnabled(string $name, bool $enabled): VpnBackendClient
    {
        $client = $this->requireClient($name)->withEnabled($enabled);
        $this->clients[$name] = $client;

        return $client;
    }
}
