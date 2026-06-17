<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use App\E2E\Support\IncusTopologyProvider;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

beforeEach(function (): void {
    putenv('GH_TOKEN');
    putenv('GITHUB_TOKEN');
});

afterEach(function (): void {
    m::close();
});

it('waits for operator host-key scan reachability before checkout pinning runs', function (): void {
    $checkoutSource = file_get_contents(repo_path('apps/e2e/app/E2E/Support/E2ECurrentCheckout.php'));
    $host = new IncusHost(incusTopologyProviderTestConfig());
    $provider = new IncusTopologyProvider(incusTopologyProviderTestConfig());

    $method = new ReflectionMethod($provider, 'peerRouteTasks');
    $method->setAccessible(true);
    $tasks = $method->invoke($provider, [
        'operator' => new IncusInstance($host, 'operator', commandTransport: true),
        'gateway' => new IncusInstance($host, 'gateway', commandTransport: true),
        'dev' => new IncusInstance($host, 'dev', commandTransport: true),
    ], incusTopologyProviderTestConfig());

    $gatewayProbe = strpos($tasks['dev'], 'StrictHostKeyChecking=accept-new');
    $operatorScan = strpos($tasks['dev'], 'ssh-keyscan');

    expect($tasks)->toHaveKey('dev')
        ->and($tasks['dev'])->toContain('ssh-keyscan -T 5 -t ed25519,ecdsa,rsa')
        ->and($gatewayProbe)->toBeInt()
        ->and($operatorScan)->toBeInt()
        ->and($gatewayProbe)->toBeLessThan($operatorScan)
        ->and($checkoutSource)->toContain("self::artisanCommand('orbit:internal:pin-node-host-keys --json'");
});

it('waits for gateway host-key reachability before incus bake commands pin host keys', function (): void {
    $source = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyProvider.php'));
    $host = new IncusHost(incusTopologyProviderTestConfig());
    $provider = new IncusTopologyProvider(incusTopologyProviderTestConfig());

    $method = new ReflectionMethod($provider, 'retargetBakeTasks');
    $method->setAccessible(true);
    $gateway = new IncusInstance($host, 'gateway', commandTransport: true);
    $instances = [
        'operator' => new IncusInstance($host, 'operator', commandTransport: true),
        'gateway' => $gateway,
        'dev' => new IncusInstance($host, 'dev', commandTransport: true),
        'prod' => new IncusInstance($host, 'prod', commandTransport: true),
        'agent' => new IncusInstance($host, 'agent', commandTransport: true),
        'ingress' => new IncusInstance($host, 'ingress', commandTransport: true),
    ];
    $tasks = $method->invoke($provider, $instances, $gateway, E2ETopologyKind::OperatorGatewayAppdevAppprodIngress, false);

    $orderings = [];

    foreach (['dev' => 'orbit:internal:bake-app-node app-dev-1', 'prod' => 'orbit:internal:bake-app-node app-prod-1', 'agent' => 'orbit:internal:bake-agent-node agent-1'] as $role => $bake) {
        $orderings[$role] = strpos($tasks[$role], 'ssh-keyscan') < strpos($tasks[$role], $bake);
    }

    $ingressBake = strpos($tasks['prod'], 'orbit:internal:bake-ingress-node edge-1');
    $prodBake = strpos($tasks['prod'], 'orbit:internal:bake-app-node app-prod-1');
    $websocketBakeTask = array_filter($tasks, fn (string $task): bool => str_contains($task, 'bake-websocket-node'));

    expect($orderings)->toBe(['dev' => true, 'prod' => true, 'agent' => true])
        ->and($ingressBake)->toBeInt()
        ->and($prodBake)->toBeInt()
        ->and($ingressBake)->toBeLessThan($prodBake)
        ->and($websocketBakeTask)->toBe([])
        ->and(strpos($source, 'retargetBakeTasks($instances, $gateway, $kind, $sourceMountedCheckout)'))
        ->toBeLessThan(strpos($source, 'seedAppdevDatabaseAndRedis($gateway, $sshKeyPair, $sourceMountedCheckout)'))
        ->and(strpos($source, 'seedAppdevDatabaseAndRedis($gateway, $sshKeyPair, $sourceMountedCheckout)'))
        ->toBeLessThan(strpos($source, 'orbit:internal:bake-websocket-node app-dev-1'));
});

it('waits for stable gateway ssh reachability after prepared incus retargeting', function (): void {
    $source = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyProvider.php'));

    $retarget = strpos($source, "\$timer->measure('retarget'");
    $networkReady = strpos($source, "\$timer->measure('network-ready'");

    expect($source)->toContain('private function gatewaySshProbeTask')
        ->and($source)->toContain('StrictHostKeyChecking=accept-new')
        ->and($source)->toContain('successes=0')
        ->and($source)->toContain('[ "$successes" -ge 3 ]')
        ->and($source)->toContain('ConnectTimeout=10')
        ->and($source)->toContain('ServerAliveInterval=30')
        ->and($source)->toContain('ServerAliveCountMax=10')
        ->and($retarget)->toBeInt()
        ->and($networkReady)->toBeInt()
        ->and($retarget)->toBeLessThan($networkReady);
});

it('seeds the gateway ssh key into prepared incus downstream clones', function (): void {
    $commands = [];
    $host = new class(incusTopologyProviderTestConfig(), $commands) extends IncusHost
    {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(E2EConfig $config, private array &$commands)
        {
            parent::__construct($config);
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (str_contains($command, 'ssh-keygen -y -f ~/.ssh/id_ed25519')) {
                return incusTopologyProviderTestProcessResult("ssh-ed25519 gateway-key orbit-e2e-gateway\n");
            }

            return incusTopologyProviderTestProcessResult();
        }
    };

    $provider = new IncusTopologyProvider(incusTopologyProviderTestConfig());
    $method = new ReflectionMethod($provider, 'seedGatewaySshAccess');
    $method->setAccessible(true);

    $method->invoke($provider, [
        'operator' => new IncusInstance($host, 'operator', commandTransport: true),
        'gateway' => new IncusInstance($host, 'gateway', commandTransport: true),
        'dev' => new IncusInstance($host, 'dev', commandTransport: true),
        'prod' => new IncusInstance($host, 'prod', commandTransport: true),
        'agent' => new IncusInstance($host, 'agent', commandTransport: true),
    ]);

    $joined = implode("\n", $commands);

    expect($joined)->toContain('cat ~/.ssh/id_ed25519.pub')
        ->and($joined)->toContain('ssh-keygen -y -f ~/.ssh/id_ed25519 > ~/.ssh/id_ed25519.pub')
        ->and($joined)->toContain("incus exec 'dev' -- sh -lc")
        ->and($joined)->toContain("incus exec 'prod' -- sh -lc")
        ->and($joined)->toContain("incus exec 'agent' -- sh -lc")
        ->and($joined)->toContain('ssh-ed25519 gateway-key orbit-e2e-gateway')
        ->and($joined)->toContain('/home/orbit/.ssh/authorized_keys')
        ->and($joined)->toContain('systemctl restart ssh || systemctl restart sshd || systemctl start ssh || systemctl start sshd')
        ->and($joined)->toContain('ss -ltn')
        ->and($joined)->not->toContain('systemctl start ssh || systemctl start sshd || true')
        ->and($joined)->not->toContain("incus exec 'operator' -- sh -lc");
});

it('seeds gateway ssh access before prepared incus retargeting can converge runtime remotely', function (): void {
    $source = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyProvider.php'));

    $initialSeed = strpos($source, "\$timer->measure('gateway-ssh-access'");
    $initialRetarget = strpos($source, "\$timer->measure('retarget'");
    $resetSeed = strpos($source, "\$cycleTimer->measure('reset.gateway-ssh-access'");
    $resetRetarget = strpos($source, "\$cycleTimer->measure('reset.retarget'");

    expect($initialSeed)->toBeInt()
        ->and($initialRetarget)->toBeInt()
        ->and($resetSeed)->toBeInt()
        ->and($resetRetarget)->toBeInt()
        ->and([
            'initial' => $initialSeed < $initialRetarget,
            'reset' => $resetSeed < $resetRetarget,
        ])->toBe([
            'initial' => true,
            'reset' => true,
        ])
        ->and($source)->toContain('orbit:internal:bake-websocket-node app-dev-1')
        ->and($source)->toContain('--converge-runtime');
});

it('keeps prepared Incus role assignment seeding out of retarget scripts', function (): void {
    $providerSource = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyProvider.php'));

    expect($providerSource)->not->toContain("'environment' => null")
        ->and($providerSource)->not->toContain("'role' => 'gateway',\n        'environment' => null")
        ->and($providerSource)->not->toContain('\\\\App\\\\Models\\\\NodeRoleAssignment::query()->updateOrCreate')
        ->and($providerSource)->toContain('writeOperatorCliConfig($operator, $config, $sshKeyPair');
});

it('retargets artifact-backed Incus gateway nodes through the gateway image instead of a source checkout', function (): void {
    $commands = [];
    $host = new class(incusTopologyProviderTestConfig(), $commands) extends IncusHost
    {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(E2EConfig $config, private array &$commands)
        {
            parent::__construct($config);
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (str_contains($command, 'ip -j -4 address show scope global') || str_contains($command, 'incus query')) {
                return incusTopologyProviderTestProcessResult("10.231.7.84\n");
            }

            return incusTopologyProviderTestProcessResult();
        }
    };

    $provider = new IncusTopologyProvider(incusTopologyProviderTestConfig());
    $method = new ReflectionMethod($provider, 'retargetTopology');
    $method->setAccessible(true);

    $method->invoke($provider, [
        'operator' => new IncusInstance($host, 'operator', commandTransport: true),
        'gateway' => new IncusInstance($host, 'gateway', commandTransport: true),
    ], incusTopologyProviderTestConfig(), new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'), E2ETopologyKind::OperatorGateway, false);

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)
        ->toContain('docker run --rm --pull never')
        ->toContain('orbit-gateway:prepared-current')
        ->toContain('artisan orbit:internal:bootstrap-gateway-local gateway')
        ->toContain('/home/operator/.config/orbit/config.json')
        ->not->toContain('cd /home/orbit/orbit && php apps/gateway/artisan')
        ->not->toContain('cd /home/operator/orbit && php apps/gateway/artisan')
        ->not->toContain('/home/operator/orbit/apps/cli');
});

it('prepares gateway state before source-mounted incus retarget bootstrap', function (): void {
    $commands = [];
    $host = new class(incusTopologyProviderTestConfig(), $commands) extends IncusHost
    {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(E2EConfig $config, private array &$commands)
        {
            parent::__construct($config);
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (str_contains($command, 'incus query')) {
                return incusTopologyProviderTestProcessResult('{"network":{"eth0":{"addresses":[{"family":"inet","scope":"global","address":"10.231.7.84"}]}}}');
            }

            return incusTopologyProviderTestProcessResult();
        }
    };

    $provider = new IncusTopologyProvider(incusTopologyProviderTestConfig());
    $method = new ReflectionMethod($provider, 'retargetTopology');
    $method->setAccessible(true);

    $method->invoke($provider, [
        'operator' => new IncusInstance($host, 'operator', commandTransport: true, sourceMountedCheckout: true),
        'gateway' => new IncusInstance($host, 'gateway', commandTransport: true, sourceMountedCheckout: true),
    ], incusTopologyProviderTestConfig(), new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'), E2ETopologyKind::OperatorGateway, true);

    $commandOutput = implode("\n", $commands);
    $stateBootstrap = strpos($commandOutput, '/home/orbit/.config/orbit/gateway.sqlite');
    $migration = strpos($commandOutput, 'php apps/gateway/artisan migrate --force --no-interaction --ansi');
    $gatewayBootstrap = strpos($commandOutput, 'php apps/gateway/artisan orbit:internal:bootstrap-gateway-local gateway');

    expect($stateBootstrap)->toBeInt()
        ->and($migration)->toBeInt()
        ->and($gatewayBootstrap)->toBeInt()
        ->and($stateBootstrap)->toBeLessThan($gatewayBootstrap)
        ->and($migration)->toBeLessThan($gatewayBootstrap)
        ->and($commandOutput)->toContain('ORBIT_CONFIG_ROOT')
        ->and($commandOutput)->toContain('/home/orbit/.config/orbit/gateway.sqlite')
        ->and($commandOutput)->toContain('/home/operator/.config/orbit/config.json')
        ->and($commandOutput)->toContain('"active_gateway":"default"')
        ->and($commandOutput)->not->toContain('LocalGatewaySettings::current')
        ->and($commandOutput)->not->toContain('/home/operator/orbit/apps/cli')
        ->not->toContain('/home/orbit/orbit/apps/gateway/database/database.sqlite');
});

function incusTopologyProviderTestConfig(): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: 'beast',
        sourceImage: '',
        baseImage: '',
        bootstrapUser: 'provisioner',
        operatorUser: 'operator',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 60,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: '1',
        topologyMemory: '2GiB',
        topologyRootSize: '16GiB',
        topologyStateSize: '4GiB',
        incusStoragePool: '',
        dockerHosts: ['local'],
        keep: false,
        incusHostVmCaps: ['beast' => 4],
    );
}

function incusTopologyProviderTestProcessResult(string $output = '', int $exitCode = 0, string $errorOutput = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($exitCode === 0);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}
