<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use App\Data\Vpn\VpnBackendClient;
use App\Data\Vpn\VpnPasswordRotationResult;
use RuntimeException;

final readonly class FileVpnBackend implements VpnBackend
{
    public function __construct(
        private string $path,
    ) {}

    public function clients(?string $totp = null): array
    {
        return array_map(
            self::clientFromArray(...),
            $this->state()['clients'],
        );
    }

    public function createClient(string $name, bool $includeConfig = false, ?string $totp = null): VpnBackendClient
    {
        $state = $this->state();

        foreach ($state['clients'] as $client) {
            if (($client['name'] ?? null) === $name) {
                throw new RuntimeException('VPN client name is already in use.');
            }
        }

        $next = count($state['clients']) + 7;
        $client = [
            'id' => "client-{$next}",
            'name' => $name,
            'address' => "10.6.0.{$next}",
            'enabled' => true,
            'latest_handshake_at' => null,
            'config' => $includeConfig ? "[Interface]\nPrivateKey = file-test\n" : null,
        ];

        $state['clients'][] = $client;
        $this->writeState($state);

        return self::clientFromArray($client);
    }

    public function enableClient(string $name, ?string $totp = null): VpnBackendClient
    {
        return $this->toggleClient($name, true);
    }

    public function disableClient(string $name, ?string $totp = null): VpnBackendClient
    {
        return $this->toggleClient($name, false);
    }

    public function removeClient(string $name, ?string $totp = null): void
    {
        $state = $this->state();
        $before = count($state['clients']);
        $state['clients'] = array_values(array_filter(
            $state['clients'],
            static fn (array $client): bool => ($client['name'] ?? null) !== $name,
        ));

        if (count($state['clients']) === $before) {
            throw new RuntimeException('VPN client does not exist.');
        }

        $this->writeState($state);
    }

    public function changeWebUiPassword(string $password, ?string $totp = null): VpnPasswordRotationResult
    {
        $state = $this->state();
        $state['password_changed'] = true;
        $state['password_length'] = mb_strlen($password);
        $state['sessions_invalidated'] = true;
        $this->writeState($state);

        return new VpnPasswordRotationResult(passwordChanged: true, sessionsInvalidated: true);
    }

    private function toggleClient(string $name, bool $enabled): VpnBackendClient
    {
        $state = $this->state();

        foreach ($state['clients'] as $index => $client) {
            if (($client['name'] ?? null) !== $name) {
                continue;
            }

            $state['clients'][$index]['enabled'] = $enabled;
            $this->writeState($state);

            return self::clientFromArray($state['clients'][$index]);
        }

        throw new RuntimeException('VPN client does not exist.');
    }

    /**
     * @return array{clients: list<array<string, mixed>>}
     */
    private function state(): array
    {
        if (! file_exists($this->path)) {
            return ['clients' => []];
        }

        $state = json_decode((string) file_get_contents($this->path), associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($state)) {
            throw new RuntimeException('VPN fake backend state is invalid.');
        }

        return array_merge($state, [
            'clients' => is_array($state['clients'] ?? null) ? array_values($state['clients']) : [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(array $state): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('VPN fake backend state directory could not be created.');
        }

        file_put_contents($this->path, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private static function clientFromArray(array $client): VpnBackendClient
    {
        $latestHandshakeAt = $client['latest_handshake_at'] ?? $client['latestHandshakeAt'] ?? null;

        return new VpnBackendClient(
            id: (string) ($client['id'] ?? ''),
            name: (string) ($client['name'] ?? ''),
            address: (string) ($client['address'] ?? $client['ipv4Address'] ?? ''),
            enabled: (bool) ($client['enabled'] ?? true),
            latestHandshakeAt: is_string($latestHandshakeAt) ? $latestHandshakeAt : null,
            config: is_string($client['config'] ?? null) ? $client['config'] : null,
        );
    }
}
