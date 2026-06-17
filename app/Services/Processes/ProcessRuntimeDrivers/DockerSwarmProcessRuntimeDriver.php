<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeDrivers;

use App\Contracts\RemoteShell;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use Throwable;

final readonly class DockerSwarmProcessRuntimeDriver implements ProcessRuntimeDriver
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    public function runtimeUnitName(App $app, Process $process, ?Workspace $workspace = null): string
    {
        $config = $this->runtimeConfig($process);
        $serviceName = $this->optionalString($config, 'service_name') ?? $process->name;

        return $this->assertServiceName($serviceName);
    }

    public function apply(Node $node, App $app, Process $process, ?Workspace $workspace = null, ?string $preApplyScript = null): bool
    {
        try {
            $runtimeUnit = $this->runtimeUnitName($app, $process, $workspace);
            $script = collect([
                $preApplyScript,
                $this->applyScript($process, $runtimeUnit),
            ])->filter(fn (?string $script): bool => $script !== null && trim($script) !== '')->implode(PHP_EOL);

            return $this->remoteShell->run($node, $script)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function remove(Node $node, string $runtimeUnit): bool
    {
        return $this->remoteShell->run($node, 'docker service rm '.escapeshellarg($this->assertServiceName($runtimeUnit)))->successful();
    }

    public function cleanupScript(string $runtimeUnit): string
    {
        return 'docker service rm '.escapeshellarg($this->assertServiceName($runtimeUnit)).' 2>/dev/null || true';
    }

    public function start(Node $node, string $runtimeUnit): bool
    {
        return $this->remoteShell->run($node, 'docker service update --replicas 1 '.escapeshellarg($this->assertServiceName($runtimeUnit)))->successful();
    }

    public function stop(Node $node, string $runtimeUnit): bool
    {
        return $this->remoteShell->run($node, 'docker service update --replicas 0 '.escapeshellarg($this->assertServiceName($runtimeUnit)))->successful();
    }

    public function restart(Node $node, string $runtimeUnit): bool
    {
        return $this->remoteShell->run($node, 'docker service update --force '.escapeshellarg($this->assertServiceName($runtimeUnit)))->successful();
    }

    public function logScript(App $app, Process $process, ?Workspace $workspace, string $runtimeUnit, int $lines, bool $follow): string
    {
        return collect([
            'docker service logs',
            "--tail {$lines}",
            $follow ? '--follow' : null,
            escapeshellarg($this->assertServiceName($runtimeUnit)),
            '2>&1',
        ])->filter()->implode(' ');
    }

    private function applyScript(Process $process, string $runtimeUnit): string
    {
        $config = $this->runtimeConfig($process);
        $specHash = $this->optionalString($config, 'spec_hash') ?? $this->optionalString($this->stringMap($config['labels'] ?? []), 'orbit.process.spec_hash') ?? '';

        return sprintf(
            <<<'SH'
set -euo pipefail
if docker service inspect %1$s >/dev/null 2>&1; then
  current_spec_hash="$(docker service inspect --format '{{ index .Spec.Labels "orbit.process.spec_hash" }}' %1$s 2>/dev/null || true)"
  if [ "$current_spec_hash" = %2$s ]; then
    exit 0
  fi
  docker service rm %1$s
fi
%3$s
SH,
            escapeshellarg($runtimeUnit),
            escapeshellarg($specHash),
            $this->createCommand($process, $runtimeUnit, $config),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createCommand(Process $process, string $runtimeUnit, array $config): string
    {
        $parts = [
            'docker service create',
            '--name',
            escapeshellarg($runtimeUnit),
            '--replicas',
            '0',
            '--restart-condition',
            escapeshellarg($this->restartCondition($process)),
        ];

        foreach ($this->stringMap($config['labels'] ?? []) as $key => $value) {
            $parts[] = '--label';
            $parts[] = escapeshellarg("{$key}={$value}");
        }

        foreach ($this->ports($config['ports'] ?? []) as $port) {
            $parts[] = '--publish';
            $parts[] = escapeshellarg($port);
        }

        foreach ($this->volumes($config['volumes'] ?? []) as $volume) {
            $parts[] = '--mount';
            $parts[] = escapeshellarg($volume);
        }

        foreach ($this->stringMap($config['environment'] ?? []) as $key => $value) {
            $parts[] = '--env';
            $parts[] = escapeshellarg("{$key}={$value}");
        }

        $updateStrategy = is_array($config['update_strategy'] ?? null) ? $config['update_strategy'] : [];
        $updateOrder = $this->optionalString($updateStrategy, 'order') ?? 'stop-first';
        $updateParallelism = (string) max(1, (int) ($updateStrategy['parallelism'] ?? 1));

        $parts[] = '--update-order';
        $parts[] = escapeshellarg($updateOrder);
        $parts[] = '--update-parallelism';
        $parts[] = escapeshellarg($updateParallelism);
        $parts[] = '--entrypoint';
        $parts[] = escapeshellarg('sh');
        $parts[] = escapeshellarg($this->requiredString($config, 'image', $process));
        $parts[] = escapeshellarg('-lc');
        $parts[] = escapeshellarg($process->command);

        return implode(' ', $parts);
    }

    private function restartCondition(Process $process): string
    {
        return match ($process->restart_policy) {
            ProcessRestartPolicy::Never => 'none',
            ProcessRestartPolicy::OnFailure => 'on-failure',
            ProcessRestartPolicy::Always => 'any',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeConfig(Process $process): array
    {
        return is_array($process->runtime_config) ? $process->runtime_config : [];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function requiredString(array $config, string $key, Process $process): string
    {
        $value = $this->optionalString($config, $key);

        if ($value !== null) {
            return $value;
        }

        throw new \InvalidArgumentException("Process '{$process->name}' is missing runtime_config.{$key}; cannot render Docker Swarm service.");
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_scalar($item)) {
                continue;
            }

            $map[$key] = (string) $item;
        }

        ksort($map);

        return $map;
    }

    /**
     * @return list<string>
     */
    private function ports(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $port): ?string {
            if (! is_array($port)) {
                return null;
            }

            $published = (int) ($port['published'] ?? 0);
            $target = (int) ($port['target'] ?? 0);
            $protocol = is_string($port['protocol'] ?? null) ? trim($port['protocol']) : 'tcp';

            if ($published < 1 || $target < 1) {
                return null;
            }

            return "published={$published},target={$target},protocol=".($protocol !== '' ? $protocol : 'tcp');
        }, $value)));
    }

    /**
     * @return list<string>
     */
    private function volumes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $volume): ?string {
            if (! is_array($volume)) {
                return null;
            }

            $source = is_string($volume['source'] ?? null) ? trim($volume['source']) : null;
            $name = is_string($volume['name'] ?? null) ? trim($volume['name']) : null;
            $target = is_string($volume['target'] ?? null) ? trim($volume['target']) : null;

            if (($source === null && $name === null) || $target === null || $target === '') {
                return null;
            }

            return 'type=volume,source='.($source ?? $name).",target={$target}";
        }, $value)));
    }

    private function assertServiceName(string $value): string
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value)) {
            throw new \InvalidArgumentException("Unsafe Docker Swarm service name: {$value}");
        }

        return $value;
    }
}
