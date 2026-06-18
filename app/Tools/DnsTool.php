<?php

declare(strict_types=1);

namespace App\Tools;

final class DnsTool extends BaseTool
{
    public function slug(): string
    {
        return 'dns';
    }

    #[\Override]
    public function category(): string
    {
        return 'infrastructure';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['update', 'safe-fix', 'safe-adopt'];
    }

    public function installScript(array $config = []): string
    {
        $orbitPath = $this->orbitPath($config);

        return "cd '{$orbitPath}' && php apps/gateway/artisan orbit:internal:install-orbit-dns";
    }

    public function removeScript(array $config = []): string
    {
        $composePath = $this->composePath($config);

        return "docker compose -f '{$composePath}' stop orbit-dns && docker compose -f '{$composePath}' rm -f orbit-dns";
    }

    public function updateScript(array $config = []): string
    {
        return $this->installScript($config);
    }

    private function orbitPath(array $config): string
    {
        $value = $config['orbit_path'] ?? '/home/orbit/orbit';

        return is_string($value) && $value !== '' ? $value : '/home/orbit/orbit';
    }

    private function composePath(array $config): string
    {
        $value = $config['compose_path'] ?? '/home/orbit/.config/orbit/docker-compose.yaml';

        return is_string($value) && $value !== '' ? $value : '/home/orbit/.config/orbit/docker-compose.yaml';
    }
}
