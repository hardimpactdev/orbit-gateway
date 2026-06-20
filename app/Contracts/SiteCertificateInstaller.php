<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Node;

interface SiteCertificateInstaller
{
    /**
     * @return array{cert: string, key: string}
     */
    public function ensureFor(Node $node, string $host): array;

    /**
     * @return array{cert: string, key: string}
     */
    public function expectedPathsFor(Node $node, string $host): array;
}
