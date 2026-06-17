<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Gateway\GatewaySwarmManager;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use App\Services\Vpn\VpnDnsSwarmManager;
use App\Services\Vpn\VpnDnsSwarmStackRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('app.key', '');

    $this->root = sys_get_temp_dir().'/orbit-vpn-dns-swarm-installer-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->root);
});

afterEach(function (): void {
    if (isset($this->root) && is_string($this->root) && is_dir($this->root)) {
        File::deleteDirectory($this->root);
    }
});

it('deploys the colocated vpn and dns Swarm services and converges forwarding', function (): void {
    $commands = [];

    Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        if ($process->command === "docker info --format '{{.Swarm.LocalNodeState}}'") {
            return Process::result(output: "active\n");
        }

        if ($process->command === "docker info --format '{{.Swarm.NodeID}}'") {
            return Process::result(output: "node-123\n");
        }

        if (str_contains((string) $process->command, 'docker network inspect')) {
            return Process::result(output: "overlay swarm true\n");
        }

        if (str_contains((string) $process->command, 'docker ps -q --filter')) {
            return Process::result(output: "vpn-container-id\n");
        }

        if (str_contains((string) $process->command, 'public-key')) {
            return Process::result(output: "server-public-key\n");
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    $installer = vpnDnsSwarmInstaller($this->root);

    $installer->install(
        publicHost: '203.0.113.10',
        username: 'orbit',
        password: 'secret-password',
    );

    expect("{$this->root}/dnsmasq.conf")->toBeFile()
        ->and(File::get("{$this->root}/dnsmasq.conf"))->toContain('address=/gateway/10.6.0.2')
        ->and("{$this->root}/swarm/orbit-vpn-dns-stack.yml")->toBeFile()
        ->and(File::get("{$this->root}/swarm/orbit-vpn-dns-stack.yml"))->toContain('orbit-vpn:')
        ->and(File::get("{$this->root}/swarm/orbit-vpn-dns-stack.yml"))->toContain('orbit-dns:')
        ->and($installer->publicKey())->toBe('server-public-key');

    $installer->configurePeers([
        [
            'name' => 'operator',
            'private_key' => 'operator-private',
            'public_key' => 'operator-public',
            'pre_shared_key' => 'operator-psk',
            'address' => '10.6.0.3',
        ],
    ]);

    expect($commands)->toContain("docker node update --label-add 'orbit.role.gateway=true' --label-add 'orbit.role.vpn=true' --label-add 'orbit.role.dns=true' 'node-123'")
        ->and($commands)->toContain("docker stack deploy -c '{$this->root}/swarm/orbit-vpn-dns-stack.yml' 'orbit'")
        ->and($commands)->toContain("set -e\nchmod 0777 '{$this->root}/wg-easy'\nchmod 0666 '{$this->root}/wg-easy/wg-easy.db'")
        ->and($commands)->toContain("docker exec 'vpn-container-id' wg show wg0 public-key")
        ->and(implode("\n", $commands))->toContain("docker exec 'vpn-container-id' sh -lc")
        ->and(implode("\n", $commands))->toContain('PREROUTING')
        ->and(implode("\n", $commands))->toContain('wg set wg0 peer');
});

it('uses the Swarm wg-easy state path for inherited state commands', function (): void {
    $installer = new class($this->root) extends VpnDnsSwarmInstaller
    {
        public function __construct(string $root)
        {
            parent::__construct(rootPath: $root);
        }

        public function exposedStatePath(): string
        {
            return $this->statePath();
        }
    };

    expect($installer->exposedStatePath())->toBe($this->root.'/wg-easy');
});

function vpnDnsSwarmInstaller(string $root): VpnDnsSwarmInstaller
{
    $renderer = new VpnDnsSwarmStackRenderer;

    return new VpnDnsSwarmInstaller(
        rootPath: $root,
        statePath: $root.'/wg-easy',
        swarm: new GatewaySwarmManager(configRoot: $root),
        renderer: $renderer,
        manager: new VpnDnsSwarmManager($renderer),
    );
}
