<?php

declare(strict_types=1);

namespace App\Tools;

use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitCaddyContainer;

final class CaddyTool extends BaseTool
{
    public function slug(): string
    {
        return 'caddy';
    }

    #[\Override]
    public function category(): string
    {
        return 'always';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['reconfigure', 'update', 'safe-fix', 'safe-adopt'];
    }

    public function reconfigureScript(array $config = []): string
    {
        $container = $this->container($config);

        return self::reloadCommand($container->name());
    }

    public static function reloadCommand(string $container = 'orbit-caddy'): string
    {
        return 'docker exec '.escapeshellarg($container).' caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile --address localhost:2019';
    }

    public function updateScript(array $config = []): string
    {
        $container = $this->container($config);
        $commands = new DockerCommandBuilder;
        $containerName = escapeshellarg($container->name());
        $imageName = escapeshellarg($container->image());

        return implode("\n", [
            'set -e',
            sprintf(
                'if ! docker image inspect %s >/dev/null 2>&1; then',
                $imageName,
            ),
            sprintf(
                '    printf %%s\\\\n %s >&2',
                escapeshellarg(sprintf(
                    'orbit-caddy: local Docker image %s is missing; run "bin/orbit-gateway-artisan orbit:internal:build-gateway-images" or "docker pull %s" before reconciling the orbit-caddy container.',
                    $container->image(),
                    $container->image(),
                )),
            ),
            '    exit 69',
            'fi',
            sprintf(
                'if ! %s >/dev/null 2>&1; then %s; fi',
                $commands->networkInspect($container->network()),
                $commands->networkCreate($container->network()),
            ),
            $this->hostConfigPreparationScript($container),
            'expected_hash='.escapeshellarg($container->specHash()),
            sprintf('if ! %s >/dev/null 2>&1; then', $commands->containerInspect($container->name())),
            '    '.$commands->runDetached($container),
            'fi',
            sprintf(
                'actual_hash="$(docker container inspect --format %s %s 2>/dev/null || true)"',
                escapeshellarg('{{ index .Config.Labels "'.OrbitCaddyContainer::SpecHashLabel.'" }}'),
                $containerName,
            ),
            'if [ "$actual_hash" != "$expected_hash" ]; then',
            '    '.$commands->containerRemove($container->name()),
            '    '.$commands->runDetached($container),
            'fi',
            sprintf(
                'running="$(docker container inspect --format %s %s 2>/dev/null || true)"',
                escapeshellarg('{{ .State.Running }}'),
                $containerName,
            ),
            'if [ "$running" != "true" ]; then',
            '    '.$commands->containerStart($container->name()),
            'fi',
        ]);
    }

    #[\Override]
    public function probeMetadata(): array
    {
        $container = OrbitCaddyContainer::default();

        return [
            'binary' => 'docker',
            'version_command' => 'docker --version',
            'container' => $container->name(),
            'image' => $container->image(),
            'update_command' => $this->updateScript(['container' => $container->spec()]),
            'repair_commands' => [
                'lifecycle_running' => 'docker start '.escapeshellarg($container->name()),
                'lifecycle_stopped' => 'docker stop '.escapeshellarg($container->name()),
                'lifecycle_restarted' => 'docker restart '.escapeshellarg($container->name()),
                'lifecycle_reloaded' => $this->reconfigureScript(['container' => $container->spec()]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function container(array $config): OrbitCaddyContainer
    {
        $container = is_array($config['container'] ?? null)
            ? $config['container']
            : [];

        return OrbitCaddyContainer::fromConfig($container);
    }

    private function hostConfigPreparationScript(OrbitCaddyContainer $container): string
    {
        $directories = implode(' ', array_map(
            escapeshellarg(...),
            $container->hostMountDirectories(),
        ));

        return sprintf(
            <<<'SH'
sudo install -d -m 0755 %s
if [ ! -f /etc/caddy/Caddyfile ]; then
    printf %%s %s | base64 -d | sudo tee /etc/caddy/Caddyfile >/dev/null
fi
SH,
            $directories,
            escapeshellarg(base64_encode((new CaddyGlobalConfig)->fresh())),
        );
    }
}
