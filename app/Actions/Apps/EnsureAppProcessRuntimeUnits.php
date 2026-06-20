<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\SiteCertificateInstaller;
use App\Models\App;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use RuntimeException;
use Throwable;

final readonly class EnsureAppProcessRuntimeUnits
{
    public function __construct(
        private SiteCertificateInstaller $siteCertificateInstaller,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(App $app): array
    {
        $app->loadMissing(['node', 'processes', 'workspaces']);

        if ($app->node === null) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        if ($app->processes->isEmpty()) {
            return [];
        }

        $this->validateProcessRuntimes($app);

        $warnings = [];

        foreach ($this->runtimeContexts($app) as $workspace) {
            $tlsWarning = $this->ensureSiteCertificate($app, $workspace);

            if ($tlsWarning !== null) {
                $warnings[] = $tlsWarning;

                continue;
            }

            foreach ($app->processes as $process) {
                if (! $process instanceof Process) {
                    continue;
                }

                if ($this->isManagedRuntimeArtifactProcess($process)) {
                    continue;
                }

                $warnings = [
                    ...$warnings,
                    ...$this->applyProcess($app, $process, $workspace),
                ];
            }
        }

        return $warnings;
    }

    /**
     * @return list<array<string, string>>
     */
    private function applyProcess(App $app, Process $process, ?Workspace $workspace): array
    {
        $driver = $this->runtimeDrivers->forProcess($process);
        $unitName = $driver->runtimeUnitName($app, $process, $workspace);

        if (! $driver->apply($app->node, $app, $process, $workspace)) {
            return [[
                'code' => 'process.runtime_unit_missing',
                'family' => 'process',
                'message' => "Process runtime unit '{$unitName}' was not enacted. Run doctor to converge process runtime units.",
                'next_command' => 'doctor --family=process --restore',
            ]];
        }

        return [];
    }

    private function isManagedRuntimeArtifactProcess(Process $process): bool
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $hashLabel = $config['container_spec_hash_label'] ?? null;

        return is_string($hashLabel) && trim($hashLabel) !== '';
    }

    private function validateProcessRuntimes(App $app): void
    {
        $app->processes->each(fn (Process $process): mixed => $this->runtimeDrivers->forProcess($process));
    }

    /**
     * @return array<string, string>|null
     */
    private function ensureSiteCertificate(App $app, ?Workspace $workspace): ?array
    {
        if ($app->node === null) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $host = $this->host($app, $workspace);

        try {
            $this->siteCertificateInstaller->ensureFor($app->node, $host);

            return null;
        } catch (Throwable) {
            return [
                'code' => 'process.tls_certificate_missing',
                'family' => 'process',
                'message' => "Process TLS certificate for '{$host}' was not installed. Run doctor to converge process runtime units.",
                'next_command' => 'doctor --family=process --restore',
            ];
        }
    }

    private function host(App $app, ?Workspace $workspace): string
    {
        $url = $workspace instanceof Workspace ? $workspace->url() : $app->url();
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return $host;
        }

        return preg_replace('#^https?://#', '', $url) ?: $app->name;
    }

    /**
     * @return list<Workspace|null>
     */
    private function runtimeContexts(App $app): array
    {
        return [
            null,
            ...$app->workspaces->all(),
        ];
    }
}
