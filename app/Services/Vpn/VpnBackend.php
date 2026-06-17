<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use App\Data\Vpn\VpnBackendClient;
use App\Data\Vpn\VpnPasswordRotationResult;

interface VpnBackend
{
    /**
     * @return list<VpnBackendClient>
     */
    public function clients(?string $totp = null): array;

    public function createClient(string $name, bool $includeConfig = false, ?string $totp = null): VpnBackendClient;

    public function enableClient(string $name, ?string $totp = null): VpnBackendClient;

    public function disableClient(string $name, ?string $totp = null): VpnBackendClient;

    public function removeClient(string $name, ?string $totp = null): void;

    public function changeWebUiPassword(string $password, ?string $totp = null): VpnPasswordRotationResult;
}
