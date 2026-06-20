<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

final readonly class GatewaySwarmManager
{
    public const string StackFile = 'orbit-gateway-stack.yml';

    public const string DeployedGatewayService = 'orbit_'.GatewaySwarmStackRenderer::GatewayService;

    public const string LegacyNetworkMessage = 'Existing Docker network [orbit-network] is not an attachable Swarm overlay. Run the explicit Orbit network migration before enabling gateway Swarm services.';

    public function __construct(
        private ?string $configRoot = null,
    ) {}

    public function ensureSwarm(): void
    {
        $result = $this->run("docker info --format '{{.Swarm.LocalNodeState}}'", 'inspect Docker Swarm state');

        $state = trim($result->output());

        if ($state === 'active') {
            return;
        }

        if ($state === '' || $state === 'inactive') {
            $this->run('docker swarm init', 'initialize Docker Swarm');

            return;
        }

        throw new RuntimeException("Docker Swarm local node state [{$state}] is not supported for gateway service deployment.");
    }

    public function ensureGatewayNodeLabel(): void
    {
        $this->ensureNodeRoleLabels(['gateway']);
    }

    /**
     * @param  list<string>  $roles
     */
    public function ensureNodeRoleLabels(array $roles): void
    {
        $labels = [];

        foreach ($roles as $role) {
            $role = $this->normalizeName($role, 'role');
            $labels[] = '--label-add '.escapeshellarg("orbit.role.{$role}=true");
        }

        if ($labels === []) {
            throw new InvalidArgumentException('At least one Swarm node role label is required.');
        }

        $this->run(
            'docker node update '.implode(' ', $labels).' '.escapeshellarg($this->localNodeId()),
            'label the local Swarm node',
        );
    }

    public function ensureGatewayEdgeNodeLabels(): void
    {
        $this->ensureNodeRoleLabels(['gateway', 'vpn', 'dns']);
    }

    public function ensureAttachableOverlayNetwork(string $network = GatewaySwarmStackRenderer::Network): void
    {
        $network = $this->normalizeName($network, 'network');
        $inspect = Process::run("docker network inspect --format '{{.Driver}} {{.Scope}} {{.Attachable}}' ".escapeshellarg($network));

        if (! $inspect->successful()) {
            $this->run('docker network create --driver overlay --attachable '.escapeshellarg($network), "create {$network} overlay network");

            return;
        }

        [$driver, $scope, $attachable] = array_pad(preg_split('/\s+/', trim($inspect->output())) ?: [], 3, null);

        if ($driver === 'overlay' && $scope === 'swarm' && $attachable === 'true') {
            return;
        }

        throw new RuntimeException(str_replace('[orbit-network]', "[{$network}]", self::LegacyNetworkMessage));
    }

    public function writeStackFile(string $contents, string $filename = self::StackFile): string
    {
        $directory = $this->configRoot().'/swarm';
        $path = $directory.'/'.$this->normalizeName($filename, 'stack file');

        File::ensureDirectoryExists($directory, 0700);
        File::put($path, $contents);

        return $path;
    }

    public function deployStack(string $stackFile, string $stack = 'orbit'): void
    {
        $this->run(
            'docker stack deploy -c '.escapeshellarg($stackFile).' '.escapeshellarg($this->normalizeName($stack, 'stack')),
            'deploy gateway Swarm stack',
        );
    }

    public function updateServiceImage(string $service, GatewayImageReference $image, string $order): void
    {
        $service = $this->normalizeName($service, 'service');
        $order = $this->normalizeUpdateOrder($order);

        $this->run(
            'docker service update --detach=true --image '.escapeshellarg($image->canonical())
            .' --update-order '.escapeshellarg($order)
            .' --update-failure-action rollback --update-monitor 60s '
            .escapeshellarg($service),
            "update {$service} image",
        );
    }

    public function scaleService(string $service, int $replicas): void
    {
        if ($replicas < 0) {
            throw new InvalidArgumentException('Swarm service replicas cannot be negative.');
        }

        $service = $this->normalizeName($service, 'service');

        $this->run(
            'docker service scale --detach=true '.escapeshellarg("{$service}={$replicas}"),
            "scale {$service}",
        );
    }

    public function serviceImage(string $service): ?string
    {
        $service = $this->normalizeName($service, 'service');
        $result = Process::run("docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' ".escapeshellarg($service));

        if (! $result->successful()) {
            return null;
        }

        $image = trim($result->output());

        return $image !== '' ? $image : null;
    }

    public function serviceReplicas(string $service): ?string
    {
        $service = $this->normalizeName($service, 'service');
        $result = Process::run('docker service ls --filter '.escapeshellarg("name={$service}")." --format '{{.Replicas}}'");

        if (! $result->successful()) {
            return null;
        }

        $replicas = trim($result->output());

        return $replicas !== '' ? $replicas : null;
    }

    public function serviceUpdateState(string $service): ?string
    {
        $service = $this->normalizeName($service, 'service');
        $result = Process::run("docker service inspect --format '{{.UpdateStatus.State}}' ".escapeshellarg($service));

        if (! $result->successful()) {
            return null;
        }

        $state = trim($result->output());

        return $state !== '' ? $state : null;
    }

    private function configRoot(): string
    {
        $configRoot = $this->configRoot ?? config('orbit.paths.config_root', '/home/orbit/.config/orbit');

        return rtrim($this->normalizePath((string) $configRoot), '/');
    }

    private function normalizeName(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("Swarm {$field} cannot be empty.");
        }

        return $value;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new InvalidArgumentException('Gateway Swarm config root cannot be empty.');
        }

        if ($path === '/') {
            return $path;
        }

        return rtrim($path, '/');
    }

    private function normalizeUpdateOrder(string $order): string
    {
        $order = trim($order);

        if (! in_array($order, ['start-first', 'stop-first'], true)) {
            throw new InvalidArgumentException('Swarm service update order must be start-first or stop-first.');
        }

        return $order;
    }

    private function localNodeId(): string
    {
        $result = $this->run("docker info --format '{{.Swarm.NodeID}}'", 'inspect local Swarm node id');
        $nodeId = trim($result->output());

        if ($nodeId === '') {
            throw new RuntimeException('Docker Swarm local node id is empty.');
        }

        return $nodeId;
    }

    private function run(string $command, string $step): ProcessResult
    {
        $result = Process::run($command);

        if ($result->successful()) {
            return $result;
        }

        $message = trim($result->errorOutput().$result->output());

        throw new RuntimeException("Failed to {$step}: {$message}");
    }
}
