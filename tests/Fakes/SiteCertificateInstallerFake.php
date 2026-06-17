<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\SiteCertificateInstaller;
use App\Models\Node;

final class SiteCertificateInstallerFake implements SiteCertificateInstaller
{
    /**
     * @var list<string>
     */
    public array $hosts = [];

    public function ensureFor(Node $node, string $host): array
    {
        $this->hosts[] = $host;

        return $this->expectedPathsFor($node, $host);
    }

    public function expectedPathsFor(Node $node, string $host): array
    {
        $user = $node->user ?: ($node->user ?: 'orbit');
        $home = $user === 'root' ? '/root' : "/home/{$user}";

        return [
            'cert' => "{$home}/.config/orbit/certs/{$host}.crt",
            'key' => "{$home}/.config/orbit/certs/{$host}.key",
        ];
    }
}
