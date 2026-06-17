<?php

declare(strict_types=1);

return (static function (): array {
    $gatewayRoot = dirname(__DIR__);
    $configRoot = getenv('ORBIT_CONFIG_ROOT');

    if (! is_string($configRoot) || trim($configRoot) === '') {
        $home = getenv('HOME');

        if (! is_string($home) || trim($home) === '') {
            $home = '/home/orbit';
        }

        $configRoot = rtrim($home, '/').'/.config/orbit';
    }

    $configRoot = rtrim($configRoot, '/');

    return [
        'config_root' => $configRoot,
        'env_path' => $configRoot.'/.env',
        'database_file' => $configRoot.'/gateway.sqlite',
        'database_path' => $gatewayRoot.'/database',
        'storage_path' => $gatewayRoot.'/storage',
    ];
})();
