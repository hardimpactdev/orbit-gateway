<?php

declare(strict_types=1);

use App\Enums\Gateway\GatewayExposureMode;
use App\Models\Node;
use App\Services\Ca\OrbitCaService;
use App\Services\Gateway\GatewayDirectFirewallInstaller;
use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmInstaller;
use App\Services\Gateway\GatewaySwarmManager;
use App\Tools\CaddyTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();

    $this->configRoot = sys_get_temp_dir().'/orbit-gateway-swarm-installer-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->configRoot);

    config()->set('orbit.paths.config_root', $this->configRoot);

    Node::factory()->gateway()->create([
        'name' => 'gateway-1',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);
});

afterEach(function (): void {
    if (isset($this->configRoot)) {
        (new SymfonyProcess(['rm', '-rf', $this->configRoot]))->run();
    }
});

it('converges gateway-direct Swarm service with CA-rooted certs and Docker-aware firewall rules', function (): void {
    $firewallScript = null;
    $stackPath = "{$this->configRoot}/swarm/".GatewaySwarmManager::StackFile;
    $invocations = [];

    Process::fake(function ($process) use (&$firewallScript, &$invocations, $stackPath) {
        $invocations[] = (string) $process->command;

        if ($process->command === 'bash -s') {
            $firewallScript = (string) $process->input;

            return Process::result();
        }

        return match ($process->command) {
            "docker info --format '{{.Swarm.LocalNodeState}}'" => Process::result(output: "active\n"),
            "docker info --format '{{.Swarm.NodeID}}'" => Process::result(output: "swarm-node-id\n"),
            "docker node update --label-add 'orbit.role.gateway=true' 'swarm-node-id'" => Process::result(),
            "docker network inspect --format '{{.Driver}} {{.Scope}} {{.Attachable}}' 'orbit-network'" => Process::result(output: "overlay swarm true\n"),
            'docker stack deploy -c '.escapeshellarg($stackPath)." 'orbit'" => Process::result(),
            "sudo ss -H -ltn 'sport = :80' | grep -q ." => Process::result(exitCode: 1),
            "sudo ss -H -ltn 'sport = :443' | grep -q ." => Process::result(exitCode: 1),
            "sudo ss -H -lun 'sport = :443' | grep -q ." => Process::result(exitCode: 1),
            default => Process::result(),
        };
    });

    $image = GatewayImageReference::fromString('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    (new GatewaySwarmInstaller(
        caService: new GatewaySwarmInstallerFakeCa($this->configRoot),
    ))->install(
        wireguardAddress: '10.6.0.2',
        image: $image,
        exposureMode: GatewayExposureMode::GatewayDirect,
    );

    $stack = File::get($stackPath);

    expect(File::get("{$this->configRoot}/certs/gateway.crt"))->toBe("issued-cert\n")
        ->and(File::get("{$this->configRoot}/certs/gateway.key"))->toBe("issued-key\n")
        ->and(File::get("{$this->configRoot}/certs/gateway.sans"))->toBe("gateway\n10.6.0.2\n")
        ->and(File::exists("{$this->configRoot}/gateway.sqlite"))->toBeTrue()
        ->and(File::get("{$this->configRoot}/.env"))->toContain('DB_BUSY_TIMEOUT=5000')
        ->and(File::get("{$this->configRoot}/.env"))->toContain('DB_JOURNAL_MODE=wal')
        ->and(File::get("{$this->configRoot}/.env"))->toContain('DB_SYNCHRONOUS=NORMAL')
        ->and(File::get("{$this->configRoot}/.env"))->toContain("DB_DATABASE={$this->configRoot}/gateway.sqlite")
        ->and($stack)->toContain('ORBIT_GATEWAY_EXPOSURE_MODE: gateway-direct')
        ->and($stack)->toContain('ORBIT_GATEWAY_TLS_CERT: /etc/orbit/certs/gateway.crt')
        ->and($stack)->toContain('ORBIT_GATEWAY_TLS_KEY: /etc/orbit/certs/gateway.key')
        ->and($stack)->toContain('published: 443')
        ->and($stack)->toContain('${ORBIT_CONFIG_ROOT:-'.$this->configRoot.'}/certs:/etc/orbit/certs:ro')
        ->and($firewallScript)->toContain('DOCKER-USER')
        ->and($firewallScript)->toContain('wg-orbit')
        ->and($firewallScript)->toContain('10.6.0.0/24')
        ->and($firewallScript)->toContain('--dport 443')
        ->and($firewallScript)->toContain('-i "$WG_IFACE" -p tcp --dport 443')
        ->and($firewallScript)->toContain('-s "$WG_CIDR" -p tcp --dport 443')
        ->and($firewallScript)->toContain('sudo ufw allow in on "$WG_IFACE" proto tcp from "$WG_CIDR" to any port 443')
        ->and($firewallScript)->toContain('sudo ufw deny in proto tcp from 0.0.0.0/0 to any port 443');

    Process::assertRan("docker info --format '{{.Swarm.LocalNodeState}}'");
    Process::assertRan("docker node update --label-add 'orbit.role.gateway=true' 'swarm-node-id'");
    Process::assertRan("docker network inspect --format '{{.Driver}} {{.Scope}} {{.Attachable}}' 'orbit-network'");
    Process::assertRan('docker stack deploy -c '.escapeshellarg($stackPath)." 'orbit'");

    $tcp80Check = array_search("sudo ss -H -ltn 'sport = :80' | grep -q .", $invocations, true);
    $tcp443Check = array_search("sudo ss -H -ltn 'sport = :443' | grep -q .", $invocations, true);
    $udp443Check = array_search("sudo ss -H -lun 'sport = :443' | grep -q .", $invocations, true);
    $stackDeploy = array_search('docker stack deploy -c '.escapeshellarg($stackPath)." 'orbit'", $invocations, true);

    expect($tcp80Check)->not->toBeFalse()
        ->and($tcp443Check)->not->toBeFalse()
        ->and($udp443Check)->not->toBeFalse()
        ->and($tcp80Check)->toBeLessThan($stackDeploy)
        ->and($tcp443Check)->toBeLessThan($stackDeploy)
        ->and($udp443Check)->toBeLessThan($stackDeploy);

    expect(array_filter(
        $invocations,
        fn (string $command): bool => str_contains($command, 'orbit-caddy') || str_contains($command, '/etc/caddy'),
    ))->toBe([]);
});

it('fails explicitly instead of publishing gateway-direct through an unsupported nftables Docker firewall backend', function (): void {
    $script = (new GatewayDirectFirewallInstaller)->script();

    expect($script)->toContain('command -v nft')
        ->and($script)->toContain('unsupported Docker nftables firewall backend')
        ->and($script)->toContain('Docker iptables firewall backend');
});

it('pulls and inspects the gateway image before deploying the stack when no archive is staged', function (): void {
    $stackPath = "{$this->configRoot}/swarm/".GatewaySwarmManager::StackFile;
    $invocations = [];
    $image = GatewayImageReference::fromString('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    Process::fake(function ($process) use (&$invocations, $image, $stackPath) {
        $invocations[] = (string) $process->command;

        return match ($process->command) {
            'bash -s' => Process::result(),
            'docker pull '.escapeshellarg($image->canonical()) => Process::result(),
            'docker image inspect '.escapeshellarg($image->canonical()) => Process::result(),
            "docker info --format '{{.Swarm.LocalNodeState}}'" => Process::result(output: "active\n"),
            "docker info --format '{{.Swarm.NodeID}}'" => Process::result(output: "swarm-node-id\n"),
            "docker node update --label-add 'orbit.role.gateway=true' 'swarm-node-id'" => Process::result(),
            "docker network inspect --format '{{.Driver}} {{.Scope}} {{.Attachable}}' 'orbit-network'" => Process::result(output: "overlay swarm true\n"),
            "sudo ss -H -ltn 'sport = :80' | grep -q ." => Process::result(exitCode: 1),
            "sudo ss -H -ltn 'sport = :443' | grep -q ." => Process::result(exitCode: 1),
            "sudo ss -H -lun 'sport = :443' | grep -q ." => Process::result(exitCode: 1),
            'docker stack deploy -c '.escapeshellarg($stackPath)." 'orbit'" => Process::result(),
            default => Process::result(),
        };
    });

    (new GatewaySwarmInstaller(
        caService: new GatewaySwarmInstallerFakeCa($this->configRoot),
    ))->install(
        wireguardAddress: '10.6.0.2',
        image: $image,
        exposureMode: GatewayExposureMode::GatewayDirect,
    );

    $pull = array_search('docker pull '.escapeshellarg($image->canonical()), $invocations, true);
    $inspect = array_search('docker image inspect '.escapeshellarg($image->canonical()), $invocations, true);
    $stackDeploy = array_search('docker stack deploy -c '.escapeshellarg($stackPath)." 'orbit'", $invocations, true);

    expect($pull)->not->toBeFalse()
        ->and($inspect)->not->toBeFalse()
        ->and($stackDeploy)->not->toBeFalse()
        ->and($pull)->toBeLessThan($inspect)
        ->and($inspect)->toBeLessThan($stackDeploy);
});

it('loads and inspects a staged gateway image archive before deploying the stack', function (): void {
    $stackPath = "{$this->configRoot}/swarm/".GatewaySwarmManager::StackFile;
    $archive = "{$this->configRoot}/orbit-gateway-current.tar";
    $invocations = [];
    $image = GatewayImageReference::fromString('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    File::put($archive, 'test archive');

    Process::fake(function ($process) use (&$invocations, $archive, $image, $stackPath) {
        $invocations[] = (string) $process->command;

        return match ($process->command) {
            'bash -s' => Process::result(),
            'docker load -i '.escapeshellarg($archive) => Process::result(),
            'docker image inspect '.escapeshellarg($image->canonical()) => Process::result(),
            "docker info --format '{{.Swarm.LocalNodeState}}'" => Process::result(output: "active\n"),
            "docker info --format '{{.Swarm.NodeID}}'" => Process::result(output: "swarm-node-id\n"),
            "docker node update --label-add 'orbit.role.gateway=true' 'swarm-node-id'" => Process::result(),
            "docker network inspect --format '{{.Driver}} {{.Scope}} {{.Attachable}}' 'orbit-network'" => Process::result(output: "overlay swarm true\n"),
            "sudo ss -H -ltn 'sport = :80' | grep -q ." => Process::result(exitCode: 1),
            "sudo ss -H -ltn 'sport = :443' | grep -q ." => Process::result(exitCode: 1),
            "sudo ss -H -lun 'sport = :443' | grep -q ." => Process::result(exitCode: 1),
            'docker stack deploy -c '.escapeshellarg($stackPath)." 'orbit'" => Process::result(),
            default => Process::result(),
        };
    });

    (new GatewaySwarmInstaller(
        caService: new GatewaySwarmInstallerFakeCa($this->configRoot),
    ))->install(
        wireguardAddress: '10.6.0.2',
        image: $image,
        exposureMode: GatewayExposureMode::GatewayDirect,
        imageArchive: $archive,
    );

    $load = array_search('docker load -i '.escapeshellarg($archive), $invocations, true);
    $inspect = array_search('docker image inspect '.escapeshellarg($image->canonical()), $invocations, true);
    $pull = array_search('docker pull '.escapeshellarg($image->canonical()), $invocations, true);
    $stackDeploy = array_search('docker stack deploy -c '.escapeshellarg($stackPath)." 'orbit'", $invocations, true);

    expect($load)->not->toBeFalse()
        ->and($inspect)->not->toBeFalse()
        ->and($pull)->toBeFalse()
        ->and($stackDeploy)->not->toBeFalse()
        ->and($load)->toBeLessThan($inspect)
        ->and($inspect)->toBeLessThan($stackDeploy);
});

it('rejects gateway-direct installation without a valid WireGuard address', function (): void {
    $image = GatewayImageReference::fromString('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    expect(fn () => (new GatewaySwarmInstaller(
        caService: new GatewaySwarmInstallerFakeCa($this->configRoot),
    ))->install(
        wireguardAddress: 'not-an-ip',
        image: $image,
        exposureMode: GatewayExposureMode::GatewayDirect,
    ))->toThrow(RuntimeException::class, 'Invalid WireGuard API address: not-an-ip');
});

it('converges router-colocated Caddy as the only host 80 443 and udp 443 listener', function (): void {
    $caddyScript = null;
    $gatewayRoute = null;
    $stackPath = "{$this->configRoot}/swarm/".GatewaySwarmManager::StackFile;
    $certReadableCommand = "docker exec 'orbit-caddy' test -r '/etc/orbit/certs/gateway.crt'";
    $keyReadableCommand = "docker exec 'orbit-caddy' test -r '/etc/orbit/certs/gateway.key'";
    $invocations = [];

    Process::fake(function ($process) use (&$caddyScript, &$gatewayRoute, &$invocations, $certReadableCommand, $keyReadableCommand, $stackPath) {
        $invocations[] = (string) $process->command;

        if ($process->command === 'bash -s') {
            $caddyScript = (string) $process->input;

            return Process::result();
        }

        if (str_contains((string) $process->command, 'tee /etc/caddy/orbit/orbit-gateway.caddy')) {
            $gatewayRoute = (string) $process->input;

            return Process::result();
        }

        return match ($process->command) {
            "docker info --format '{{.Swarm.LocalNodeState}}'" => Process::result(output: "active\n"),
            "docker info --format '{{.Swarm.NodeID}}'" => Process::result(output: "swarm-node-id\n"),
            "docker node update --label-add 'orbit.role.gateway=true' 'swarm-node-id'" => Process::result(),
            "docker network inspect --format '{{.Driver}} {{.Scope}} {{.Attachable}}' 'orbit-network'" => Process::result(output: "overlay swarm true\n"),
            'docker stack deploy -c '.escapeshellarg($stackPath)." 'orbit'" => Process::result(),
            $certReadableCommand => Process::result(),
            $keyReadableCommand => Process::result(),
            CaddyTool::reloadCommand('orbit-caddy') => Process::result(),
            "sudo ss -H -ltn 'sport = :80' | grep -q ." => Process::result(exitCode: 1),
            "sudo ss -H -ltn 'sport = :443' | grep -q ." => Process::result(exitCode: 1),
            "sudo ss -H -lun 'sport = :443' | grep -q ." => Process::result(exitCode: 1),
            default => Process::result(),
        };
    });

    $image = GatewayImageReference::fromString('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    (new GatewaySwarmInstaller(
        caService: new GatewaySwarmInstallerFakeCa($this->configRoot),
    ))->install(
        wireguardAddress: '10.6.0.2',
        image: $image,
        exposureMode: GatewayExposureMode::RouterColocated,
    );

    $stack = File::get($stackPath);
    $gatewayBlock = substr($stack, strpos($stack, '  orbit-gateway:'), strpos($stack, '  orbit-scheduler:') - strpos($stack, '  orbit-gateway:'));

    expect(File::get("{$this->configRoot}/certs/gateway.sans"))->toBe("gateway\n10.6.0.2\n")
        ->and($gatewayBlock)->toContain('ORBIT_GATEWAY_EXPOSURE_MODE: router-colocated')
        ->and($gatewayBlock)->toContain('ORBIT_TRUST_WIREGUARD_PROXY_HEADER: "1"')
        ->and($gatewayBlock)->toContain('- orbit-gateway')
        ->and($gatewayBlock)->not->toContain('ports:')
        ->and($caddyScript)->toContain('orbit-caddy')
        ->and($caddyScript)->not->toContain('orbit-gateway-caddy')
        ->and($caddyScript)->toContain("--publish '80:80'")
        ->and($caddyScript)->toContain("--publish '443:443'")
        ->and($caddyScript)->toContain("--publish '443:443/udp'")
        ->and($gatewayRoute)->toContain('10.6.0.2 :443 {')
        ->and($gatewayRoute)->toContain('tls /etc/orbit/certs/gateway.crt /etc/orbit/certs/gateway.key')
        ->and($gatewayRoute)->toContain('remote_ip 10.6.0.0/24')
        ->and($gatewayRoute)->not->toContain('client_ip')
        ->and($gatewayRoute)->toContain('abort @notWireGuard')
        ->and($gatewayRoute)->toContain('reverse_proxy http://orbit-gateway:8080')
        ->and($gatewayRoute)->toContain('flush_interval -1');

    Process::assertRan('sudo tee /etc/caddy/orbit/orbit-gateway.caddy > /dev/null');
    Process::assertRan($certReadableCommand);
    Process::assertRan($keyReadableCommand);
    Process::assertRan(CaddyTool::reloadCommand('orbit-caddy'));

    $stackDeploy = array_search('docker stack deploy -c '.escapeshellarg($stackPath)." 'orbit'", $invocations, true);
    $routeWrite = array_search('sudo tee /etc/caddy/orbit/orbit-gateway.caddy > /dev/null', $invocations, true);
    $certReadable = array_search($certReadableCommand, $invocations, true);
    $keyReadable = array_search($keyReadableCommand, $invocations, true);
    $caddyReload = array_search(CaddyTool::reloadCommand('orbit-caddy'), $invocations, true);
    $tcp80Check = array_search("sudo ss -H -ltn 'sport = :80' | grep -q .", $invocations, true);
    $tcp443Check = array_search("sudo ss -H -ltn 'sport = :443' | grep -q .", $invocations, true);
    $udp443Check = array_search("sudo ss -H -lun 'sport = :443' | grep -q .", $invocations, true);
    $caddyConverge = array_search('bash -s', $invocations, true);

    expect($stackDeploy)->not->toBeFalse()
        ->and($tcp80Check)->not->toBeFalse()
        ->and($tcp443Check)->not->toBeFalse()
        ->and($udp443Check)->not->toBeFalse()
        ->and($caddyConverge)->not->toBeFalse()
        ->and($routeWrite)->not->toBeFalse()
        ->and($certReadable)->not->toBeFalse()
        ->and($keyReadable)->not->toBeFalse()
        ->and($caddyReload)->not->toBeFalse()
        ->and($stackDeploy)->toBeLessThan($tcp80Check)
        ->and($tcp80Check)->toBeLessThan($caddyConverge)
        ->and($tcp443Check)->toBeLessThan($caddyConverge)
        ->and($udp443Check)->toBeLessThan($caddyConverge)
        ->and($caddyConverge)->toBeLessThan($routeWrite)
        ->and($routeWrite)->toBeLessThan($certReadable)
        ->and($certReadable)->toBeLessThan($keyReadable)
        ->and($keyReadable)->toBeLessThan($caddyReload);
});

readonly class GatewaySwarmInstallerFakeCa extends OrbitCaService
{
    public function __construct(
        private string $configRoot,
    ) {}

    /**
     * @param  list<string>  $additionalSans
     * @return array{cert: string, key: string}
     */
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        $certsDir = "{$this->configRoot}/certs";

        File::ensureDirectoryExists($certsDir);
        File::put("{$certsDir}/{$host}.crt", "issued-cert\n");
        File::put("{$certsDir}/{$host}.key", "issued-key\n");
        File::put("{$certsDir}/{$host}.sans", implode("\n", [$host, ...$additionalSans])."\n");

        return [
            'cert' => "{$certsDir}/{$host}.crt",
            'key' => "{$certsDir}/{$host}.key",
        ];
    }
}
