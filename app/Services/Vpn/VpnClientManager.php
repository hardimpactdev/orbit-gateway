<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use App\Data\Vpn\VpnBackendClient;
use App\Data\Vpn\VpnClient;
use App\Data\Vpn\VpnClientMutationResult;
use App\Data\Vpn\VpnPasswordRotationResult;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use RuntimeException;

final readonly class VpnClientManager
{
    public function __construct(
        private VpnBackend $backend,
    ) {}

    /**
     * @return list<VpnClient>
     */
    public function list(?string $totp = null): array
    {
        return array_map(
            fn (VpnBackendClient $client): VpnClient => $this->classify($client),
            $this->backend->clients($totp),
        );
    }

    public function create(string $name, bool $includeConfig, ?string $totp = null): VpnClient|VpnFailure
    {
        $name = trim($name);

        if ($name === '') {
            return $this->validationFailure('VPN client name is required.', 'name');
        }

        if ($this->activeNodeNameExists($name)) {
            return $this->validationFailure('VPN client name is reserved for an active Orbit node.', 'name', 'node_name_reserved');
        }

        try {
            foreach ($this->backend->clients($totp) as $client) {
                if ($client->name === $name) {
                    return $this->validationFailure('VPN client name is already in use.', 'name', 'duplicate_client');
                }
            }

            return $this->classify($this->backend->createClient($name, $includeConfig, $totp), forceKind: 'admin');
        } catch (RuntimeException $e) {
            return $this->backendFailure($e);
        }
    }

    public function enable(string $name, ?string $totp = null): VpnClientMutationResult|VpnFailure
    {
        return $this->toggle($name, true, $totp);
    }

    public function disable(string $name, ?string $totp = null): VpnClientMutationResult|VpnFailure
    {
        return $this->toggle($name, false, $totp);
    }

    public function remove(string $name, ?string $totp = null): array|VpnFailure
    {
        $client = $this->findMutableClient($name, $totp);

        if ($client instanceof VpnFailure) {
            return $client;
        }

        try {
            $this->backend->removeClient($name, $totp);
        } catch (RuntimeException $e) {
            return $this->backendFailure($e);
        }

        return [
            'name' => $client->name,
            'action' => 'removed',
        ];
    }

    public function changeWebUiPassword(string $password, ?string $totp = null): VpnPasswordRotationResult|VpnFailure
    {
        if (mb_strlen($password) < 12) {
            return $this->validationFailure('Password must be at least 12 characters.', 'password');
        }

        try {
            return $this->backend->changeWebUiPassword($password, $totp);
        } catch (RuntimeException $e) {
            return new VpnFailure(
                code: str_contains($e->getMessage(), 'authentication') ? 'vpn_backend_auth_failed' : 'vpn_credential_rotation_failed',
                message: str_contains($e->getMessage(), 'authentication') ? 'VPN backend authentication failed.' : 'VPN credential rotation failed.',
                meta: str_contains($e->getMessage(), 'authentication') ? [] : ['step' => 'credential_store'],
            );
        }
    }

    private function toggle(string $name, bool $enabled, ?string $totp): VpnClientMutationResult|VpnFailure
    {
        $client = $this->findMutableClient($name, $totp);

        if ($client instanceof VpnFailure) {
            return $client;
        }

        $already = $client->enabled === $enabled;

        try {
            $backendClient = $enabled
                ? $this->backend->enableClient($name, $totp)
                : $this->backend->disableClient($name, $totp);
        } catch (RuntimeException $e) {
            return $this->backendFailure($e);
        }

        return new VpnClientMutationResult(
            client: $this->classify($backendClient, forceKind: 'admin'),
            action: $enabled ? 'enabled' : 'disabled',
            alreadyInDesiredState: $already,
        );
    }

    private function findMutableClient(string $name, ?string $totp): VpnClient|VpnFailure
    {
        try {
            foreach ($this->list($totp) as $client) {
                if ($client->name !== $name) {
                    continue;
                }

                if ($client->kind === 'node') {
                    return $this->nodePeerFailure($client->name);
                }

                if ($client->kind !== 'admin') {
                    return $this->validationFailure('VPN client cannot be safely classified as mutable.', 'name', 'unknown_peer');
                }

                return $client;
            }
        } catch (RuntimeException $e) {
            return $this->backendFailure($e);
        }

        return $this->validationFailure('VPN client does not exist.', 'name', 'client_not_found');
    }

    private function classify(VpnBackendClient $client, ?string $forceKind = null): VpnClient
    {
        $kind = $forceKind ?? match (true) {
            $this->activeNodePeerExists($client) => 'node',
            $client->name === '' || $client->address === '' => 'unknown',
            default => 'admin',
        };

        return new VpnClient(
            id: $client->id,
            name: $client->name,
            address: $client->address,
            enabled: $client->enabled,
            latestHandshakeAt: $client->latestHandshakeAt,
            kind: $kind,
            config: $client->config,
        );
    }

    private function activeNodePeerExists(VpnBackendClient $client): bool
    {
        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->where(function ($query) use ($client): void {
                $query->where('name', $client->name);

                if ($client->address !== '') {
                    $query->orWhere('wireguard_address', $client->address);
                }
            })
            ->exists();
    }

    private function activeNodeNameExists(string $name): bool
    {
        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->where('name', $name)
            ->exists();
    }

    private function validationFailure(string $message, string $field, ?string $reason = null): VpnFailure
    {
        return new VpnFailure(
            code: 'validation_failed',
            message: $message,
            meta: array_filter([
                'field' => $field,
                'reason' => $reason,
            ], fn (?string $value): bool => $value !== null),
        );
    }

    private function nodePeerFailure(string $name): VpnFailure
    {
        return new VpnFailure(
            code: 'validation_failed',
            message: 'VPN client is an active Orbit node peer.',
            meta: [
                'field' => 'name',
                'reason' => 'node_peer_protected',
                'next_command' => "node:remove {$name}",
            ],
        );
    }

    private function backendFailure(RuntimeException $e): VpnFailure
    {
        $message = $e->getMessage();

        if (str_contains($message, 'authentication') || str_contains($message, 'TOTP')) {
            return new VpnFailure('vpn_backend_auth_failed', 'VPN backend authentication failed.');
        }

        return new VpnFailure('vpn_backend_unavailable', $message !== '' ? $message : 'VPN backend unavailable.');
    }
}
