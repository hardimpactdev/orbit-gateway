<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Contracts\RemoteShell;
use App\Models\Node;
use App\Services\Ca\OrbitCaService;
use Illuminate\Support\Facades\File;
use RuntimeException;

class WebSocketCertificateInstaller
{
    public const CertificateDirectory = '/etc/orbit/certs';

    public function __construct(
        private readonly OrbitCaService $ca,
        private readonly RemoteShell $remoteShell,
        private readonly WebSocketBackendName $backendName,
    ) {}

    /**
     * Install backend TLS material through the RemoteHostExecutor lane because
     * this writes host-owned `/etc/orbit/certs` artifacts consumed by workload
     * containers. It does not execute Orbit PHP/artisan on the target node.
     *
     * @see apps/docs/content/execution-lanes.md
     *
     * @return array{cert: string, key: string}
     */
    public function ensureFor(Node $node): array
    {
        $backendName = $this->backendName->forNode($node);
        $wireGuardAddress = $this->wireGuardAddress($node);
        $local = $this->ca->issueLeaf($backendName, [$wireGuardAddress]);
        $remote = $this->pathsForBackend($backendName);

        $this->remoteShell->run($node, $this->installScript(
            certPath: $remote['cert'],
            cert: File::get($local['cert']),
            keyPath: $remote['key'],
            key: File::get($local['key']),
        ), [
            'throw' => true,
            'metadata' => [
                'ORBIT_OPERATION_ID' => 'websocket-certificate-install',
            ],
        ]);

        return $remote;
    }

    /**
     * @return array{cert: string, key: string}
     */
    public function expectedPathsFor(Node $node): array
    {
        return $this->pathsForBackend($this->backendName->forNode($node));
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function pathsForBackend(string $backendName): array
    {
        return [
            'cert' => self::CertificateDirectory."/{$backendName}.crt",
            'key' => self::CertificateDirectory."/{$backendName}.key",
        ];
    }

    private function wireGuardAddress(Node $node): string
    {
        $wireGuardAddress = trim((string) $node->wireguard_address);

        if ($wireGuardAddress === '') {
            throw new RuntimeException('The websocket backend requires a WireGuard address.');
        }

        return $wireGuardAddress;
    }

    private function installScript(string $certPath, string $cert, string $keyPath, string $key): string
    {
        return sprintf(
            <<<'SH'
set -e
sudo install -d -m 0755 %s
printf %%s %s | base64 -d | sudo tee %s >/dev/null
printf %%s %s | base64 -d | sudo tee %s >/dev/null
sudo chmod 0644 %s
sudo chmod 0600 %s
SH,
            escapeshellarg(dirname($certPath)),
            escapeshellarg(base64_encode($cert)),
            escapeshellarg($certPath),
            escapeshellarg(base64_encode($key)),
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
            escapeshellarg($keyPath),
        );
    }
}
