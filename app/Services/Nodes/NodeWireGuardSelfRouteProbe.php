<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Models\Node;

final readonly class NodeWireGuardSelfRouteProbe
{
    public const string UnsupportedMessage = 'WireGuard self-route diagnostics are only supported on Linux.';

    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     supported: bool,
     *     reason: string|null,
     *     message: string,
     *     platform: string|null,
     *     wireguard_address: string|null,
     *     command: string|null,
     *     exit_code: int|null,
     *     output: string
     * }
     */
    public function probe(Node $node): array
    {
        $platform = $this->normalizedPlatform($node);
        $address = trim((string) $node->wireguard_address);

        if ($address === '') {
            return $this->result(
                ok: false,
                supported: false,
                reason: 'wireguard_address_missing',
                message: 'WireGuard self-route diagnostics require a node WireGuard address.',
                platform: $platform,
                address: null,
            );
        }

        if ($this->isUnsupportedPlatform($platform)) {
            return $this->result(
                ok: false,
                supported: false,
                reason: 'unsupported_platform',
                message: self::UnsupportedMessage,
                platform: $platform,
                address: $address,
            );
        }

        $command = 'ip route get '.escapeshellarg($address);
        $result = $this->remoteShell->run($node, $command, ['throw' => false]);
        $output = trim($result->output());

        if (! $result->successful()) {
            return $this->result(
                ok: false,
                supported: true,
                reason: 'route_unverifiable',
                message: 'WireGuard self-route could not be inspected with ip route get.',
                platform: $platform,
                address: $address,
                command: $command,
                exitCode: $result->exitCode,
                output: $output,
            );
        }

        if ($this->hasLocalRoute($output, $address)) {
            return $this->result(
                ok: true,
                supported: true,
                reason: null,
                message: 'Linux node routes its own WireGuard address locally.',
                platform: $platform,
                address: $address,
                command: $command,
                exitCode: $result->exitCode,
                output: $output,
            );
        }

        return $this->result(
            ok: false,
            supported: true,
            reason: 'self_route_missing',
            message: 'Linux node does not route its own WireGuard address locally.',
            platform: $platform,
            address: $address,
            command: $command,
            exitCode: $result->exitCode,
            output: $output,
        );
    }

    private function normalizedPlatform(Node $node): ?string
    {
        $platform = trim((string) $node->platform);

        return $platform === '' ? null : strtolower($platform);
    }

    private function isUnsupportedPlatform(?string $platform): bool
    {
        if ($platform === null) {
            return false;
        }

        return $platform === 'macos'
            || $platform === 'darwin'
            || str_starts_with($platform, 'macos_')
            || str_starts_with($platform, 'darwin_');
    }

    private function hasLocalRoute(string $output, string $address): bool
    {
        $quotedAddress = preg_quote($address, '/');

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s+/', ' ', $line));

            if (preg_match("/^local {$quotedAddress}\\b.*\\bdev\\s+\\S+/", $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     ok: bool,
     *     supported: bool,
     *     reason: string|null,
     *     message: string,
     *     platform: string|null,
     *     wireguard_address: string|null,
     *     command: string|null,
     *     exit_code: int|null,
     *     output: string
     * }
     */
    private function result(
        bool $ok,
        bool $supported,
        ?string $reason,
        string $message,
        ?string $platform,
        ?string $address,
        ?string $command = null,
        ?int $exitCode = null,
        string $output = '',
    ): array {
        return [
            'ok' => $ok,
            'supported' => $supported,
            'reason' => $reason,
            'message' => $message,
            'platform' => $platform,
            'wireguard_address' => $address,
            'command' => $command,
            'exit_code' => $exitCode,
            'output' => $output,
        ];
    }
}
