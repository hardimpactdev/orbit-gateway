<?php

declare(strict_types=1);

namespace App\Services\Ca;

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Models\Node;
use Illuminate\Support\Facades\File;
use RuntimeException;

final readonly class OrbitSiteCertificateInstaller implements SiteCertificateInstaller
{
    public function __construct(
        private OrbitCaService $ca,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array{cert: string, key: string}
     */
    public function ensureFor(Node $node, string $host): array
    {
        $this->assertSafeHost($host);

        $local = $this->ca->issueLeaf($host);
        $remote = $this->expectedPathsFor($node, $host);

        $this->remoteShell->run($node, $this->installScript(
            certPath: $remote['cert'],
            cert: File::get($local['cert']),
            keyPath: $remote['key'],
            key: File::get($local['key']),
        ), ['throw' => true]);

        return $remote;
    }

    /**
     * @return array{cert: string, key: string}
     */
    public function expectedPathsFor(Node $node, string $host): array
    {
        $this->assertSafeHost($host);

        $base = $this->nodeHome($node).'/.config/orbit/certs';

        return [
            'cert' => "{$base}/{$host}.crt",
            'key' => "{$base}/{$host}.key",
        ];
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

    private function nodeHome(Node $node): string
    {
        $user = $node->user ?: 'orbit';

        return $user === 'root' ? '/root' : "/home/{$user}";
    }

    private function assertSafeHost(string $host): void
    {
        if ($host === '' || preg_match('#[/\\\\\s]#', $host) === 1) {
            throw new RuntimeException("Invalid host for site certificate: {$host}");
        }
    }
}
