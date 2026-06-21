<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Mockery as m;

beforeEach(function (): void {
    putenv('GH_TOKEN');
    putenv('GITHUB_TOKEN');
});

afterEach(function (): void {
    m::close();
});

function incusHostTestConfig(string $incusStoragePool = '', string $host = 'beast'): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: $host,
        sourceImage: 'images:ubuntu/26.04',
        baseImage: 'orbit-base-ubuntu-26.04-runtime',
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
        incusStoragePool: $incusStoragePool,
        dockerHosts: ['local'],
        keep: false,
    );
}

function incusHostTestProcessResult(string $output = '', int $exitCode = 0): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($exitCode === 0);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn('');
    $result->shouldReceive('exitCode')->andReturn($exitCode);

    return $result;
}

function recordingIncusHost(E2EConfig $config, array &$commands, ?array &$inputs = null): IncusHost
{
    if ($inputs === null) {
        $inputs = [];
    }

    return new class($config, $commands, $inputs) extends IncusHost
    {
        /** @var list<string> */
        private array $commands;

        /** @var list<string> */
        private array $inputs;

        /**
         * @param  list<string>  $commands
         * @param  list<string>  $inputs
         */
        public function __construct(E2EConfig $config, array &$commands, array &$inputs)
        {
            parent::__construct($config);
            $this->commands = &$commands;
            $this->inputs = &$inputs;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return incusHostTestProcessResult();
        }

        public function runWithInput(string $command, string $input, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;
            $this->inputs[] = $input;

            return incusHostTestProcessResult();
        }
    };
}

it('adds configured storage pool to launch and copy commands', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig('orbit-e2e'), $commands);

    $host->launchInstance('orbit-base-ubuntu-26.04-runtime', 'orbit-template-operator');
    $host->copyInstance('orbit-template-operator/clean-operator', 'orbit-e2e-run-operator');

    expect($commands[0])->toContain("incus launch 'orbit-base-ubuntu-26.04-runtime' 'orbit-template-operator' --vm --storage 'orbit-e2e' >/dev/null")
        ->and($commands[1])->toContain("incus copy 'orbit-template-operator/clean-operator' 'orbit-e2e-run-operator' --storage 'orbit-e2e'");
});

it('sets the configured root disk size when launching topology instances', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->launchTopologyInstance('orbit-base-ubuntu-26.04-runtime', 'orbit-template-operator');

    expect($commands[0])->toContain("incus launch 'orbit-base-ubuntu-26.04-runtime' 'orbit-template-operator' --vm --config=limits.cpu='1' --config=limits.memory='2GiB' --device root,size='16GiB' >/dev/null");
});

it('uses incus snapshot restore and supports stateful restore', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->restoreSnapshot('orbit-e2e-run-operator', 'lease-clean');
    $host->restoreSnapshot('orbit-e2e-run-operator', 'lease-warm', stateful: true);

    expect($commands[0])->toContain("incus snapshot restore 'orbit-e2e-run-operator' 'lease-clean'")
        ->and($commands[1])->toContain("incus snapshot restore 'orbit-e2e-run-operator' 'lease-warm' --stateful");
});

it('validates an explicit remote source path before using it for Incus mounts', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(host: 'beast'), $commands);

    withE2EConfigEnvironment([
        'ORBIT_E2E_INCUS_SOURCE_PATH' => '/srv/orbit-source',
    ], function () use ($host, &$commands): void {
        expect($host->sourcePath())->toBe('/srv/orbit-source');
    });

    expect($commands)->toContain("test -d '/srv/orbit-source' && test -f '/srv/orbit-source/apps/cli/orbit'");
});

it('fails clearly when an explicit Incus source path is not visible on the host', function (): void {
    $host = new class(incusHostTestConfig(host: 'beast')) extends IncusHost
    {
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return incusHostTestProcessResult('missing source', 1);
        }
    };

    withE2EConfigEnvironment([
        'ORBIT_E2E_INCUS_SOURCE_PATH' => '/missing/orbit-source',
    ], function () use ($host): void {
        expect(fn () => $host->sourcePath())
            ->toThrow(RuntimeException::class, 'Configured Incus source path [/missing/orbit-source] is not visible on host [beast]');
    });
});

it('uses reusable stateful snapshots for warm topology reset points', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->snapshotStatefulInstance('orbit-e2e-run-operator', 'lease-warm');

    expect($commands[0])->toContain("incus snapshot create 'orbit-e2e-run-operator' 'lease-warm' --stateful --reuse");
});

it('force stops instances when graceful incus stop times out', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->stopInstance('orbit-template-operator');

    expect($commands[0])->toContain("incus stop 'orbit-template-operator' --timeout 120 || incus stop 'orbit-template-operator' --force");
});

it('force stops an instance immediately', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->forceStopInstance('orbit-template-operator');

    expect($commands[0])->toContain("incus stop 'orbit-template-operator' --force");
});

it('force stops reusable template instances only when they are running', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->stopInstancesIfRunning([
        'orbit-template-operator',
        'orbit-template-gateway',
    ]);

    expect($commands[0])->toContain("incus stop 'orbit-template-operator' --force >/dev/null 2>&1 || true")
        ->and($commands[0])->toContain("incus stop 'orbit-template-gateway' --force >/dev/null 2>&1 || true");
});

it('checks snapshots by exact Incus snapshot path', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->snapshotExists('orbit-template-operator', 'clean-operator_gateway');

    expect($commands[0])->toContain("incus query '/1.0/instances/orbit-template-operator/snapshots/clean-operator_gateway' >/dev/null 2>&1")
        ->and($commands[0])->not->toContain('grep -q');
});

it('uses fresh ssh transport for remote checkpoint text files', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: str_contains($process->command, 'cat') ? 'manifest' : '');
    });
    Process::preventStrayProcesses();

    $host = new IncusHost(incusHostTestConfig());

    expect($host->readTextFile('.cache/orbit-e2e/provision-checkpoints/base/full.json'))->toBe("manifest\n")
        ->and($host->writeTextFile('.cache/orbit-e2e/provision-checkpoints/base/full.json', '{}')->successful())->toBeTrue()
        ->and($commands)->toHaveCount(2)
        ->and($commands[0])->toContain('ssh -S none -o ControlMaster=no -o BatchMode=yes -o ConnectTimeout=10')
        ->and($commands[0])->toContain('test -f')
        ->and($commands[0])->toContain('cat')
        ->and($commands[0])->toContain('.cache/orbit-e2e/provision-checkpoints/base/full.json')
        ->and($commands[1])->toContain('ssh -S none -o ControlMaster=no -o BatchMode=yes -o ConnectTimeout=10')
        ->and($commands[1])->toContain('mkdir -p')
        ->and($commands[1])->toContain('printf %s')
        ->and($commands[1])->toContain('e30=')
        ->and($commands[1])->toContain('base64 -d')
        ->and($commands[1])->toContain('.cache/orbit-e2e/provision-checkpoints/base/full.json');
});

it('queries live guest state first when resolving a provider IPv4', function (): void {
    $commands = [];

    $host = new class(incusHostTestConfig(), $commands) extends IncusHost
    {
        /** @var list<string> */
        private array $commands;

        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, array &$commands)
        {
            parent::__construct($config);
            $this->commands = &$commands;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return incusHostTestProcessResult("10.231.0.10\n");
        }
    };

    $instance = new IncusInstance($host, 'orbit-template-operator');

    expect($instance->waitForIpv4())->toBe('10.231.0.10')
        ->and($commands[0])->toContain("incus exec 'orbit-template-operator' -- sh -lc 'ip -j -4 address show scope global'")
        ->and($commands[0])->toContain('python3 -c');
});

it('falls back to exact Incus instance state when guest IPv4 lookup fails', function (): void {
    $commands = [];

    $host = new class(incusHostTestConfig(), $commands) extends IncusHost
    {
        /** @var list<string> */
        private array $commands;

        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, array &$commands)
        {
            parent::__construct($config);
            $this->commands = &$commands;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (str_contains($command, 'ip -j -4 address show scope global')) {
                return incusHostTestProcessResult('', 1);
            }

            return incusHostTestProcessResult("10.231.0.10\n");
        }
    };

    $instance = new IncusInstance($host, 'orbit-template-operator');

    expect($instance->waitForIpv4())->toBe('10.231.0.10')
        ->and($commands[1])->toContain("incus query '/1.0/instances/orbit-template-operator/state'")
        ->and($commands[1])->toContain('python3 -c')
        ->and($commands[1])->toContain("awk -F, -v name='orbit-template-operator'");
});

it('restarts journald after refreshing cloned instance network identity', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);
    $instance = new IncusInstance($host, 'orbit-e2e-run-dev');

    $instance->refreshNetworkIdentity();

    expect($commands[0])->toContain('systemd-machine-id-setup')
        ->and($commands[0])->toContain('systemctl restart systemd-journald')
        ->and($commands[0])->toContain('systemctl --no-block restart systemd-networkd')
        ->and($commands[0])->toContain('systemctl --no-block restart NetworkManager');
});

it('passes GitHub auth into Incus command transport without changing the reported test command', function (): void {
    $previousGhToken = getenv('GH_TOKEN');
    $previousGithubToken = getenv('GITHUB_TOKEN');
    putenv('GH_TOKEN=ghp_incus_secret');
    putenv('GITHUB_TOKEN');

    try {
        $commands = [];
        $inputs = [];
        $host = recordingIncusHost(incusHostTestConfig(), $commands, $inputs);
        $instance = new IncusInstance($host, 'orbit-e2e-run-gateway', commandTransport: true);

        $instance->ssh('orbit', new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'), 'php apps/gateway/artisan about');

        expect($commands[0])
            ->toContain("incus exec 'orbit-e2e-run-gateway' -- runuser -u 'orbit' -- bash -s")
            ->not->toContain('ghp_incus_secret')
            ->and($inputs)->toHaveCount(1)
            ->and($inputs[0])
            ->toContain("export GH_TOKEN='ghp_incus_secret'")
            ->toContain("export GITHUB_TOKEN='ghp_incus_secret'")
            ->toContain('php apps/gateway/artisan about');
    } finally {
        is_string($previousGhToken) ? putenv("GH_TOKEN={$previousGhToken}") : putenv('GH_TOKEN');
        is_string($previousGithubToken) ? putenv("GITHUB_TOKEN={$previousGithubToken}") : putenv('GITHUB_TOKEN');
    }
});

it('passes GitHub auth into the in-guest Incus provisioner when available', function (): void {
    $previousGhToken = getenv('GH_TOKEN');
    $previousGithubToken = getenv('GITHUB_TOKEN');
    putenv('GH_TOKEN=ghp_provision_secret');
    putenv('GITHUB_TOKEN');

    try {
        $commands = [];
        $inputs = [];
        $host = recordingIncusHost(incusHostTestConfig(), $commands, $inputs);

        $host->provisionInstance('orbit-e2e-run-gateway', 'gateway', '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle', 'orbit');

        $commandOutput = implode("\n", $commands);
        $inputOutput = implode("\n", $inputs);

        expect($commandOutput)
            ->toContain("incus exec 'orbit-e2e-run-gateway' -- bash -s")
            ->not->toContain('ghp_provision_secret')
            ->and($inputOutput)
            ->toContain("export GH_TOKEN='ghp_provision_secret'")
            ->toContain("export GITHUB_TOKEN='ghp_provision_secret'")
            ->toContain('/var/tmp/orbit-e2e-bundle/e2e-provision-node');
    } finally {
        is_string($previousGhToken) ? putenv("GH_TOKEN={$previousGhToken}") : putenv('GH_TOKEN');
        is_string($previousGithubToken) ? putenv("GITHUB_TOKEN={$previousGithubToken}") : putenv('GITHUB_TOKEN');
    }
});

it('keeps locally staged files readable before pushing them into an incus instance', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-incus-source-');
    file_put_contents($source, 'archive');
    chmod($source, 0644);

    $pushedMode = null;
    $commands = [];
    $host = new class(incusHostTestConfig(host: 'localhost'), $commands, $pushedMode) extends IncusHost
    {
        /** @var list<string> */
        private array $commands;

        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, array &$commands, private ?string &$pushedMode)
        {
            parent::__construct($config);
            $this->commands = &$commands;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (preg_match("/^incus file push '([^']+)' /", $command, $matches) === 1) {
                $this->pushedMode = decoct(fileperms($matches[1]) & 0777);
            }

            return incusHostTestProcessResult();
        }
    };
    $instance = new IncusInstance($host, 'orbit-template-operator');
    $previousUmask = umask(0077);

    try {
        $instance->copyLocalFileToInstance($source, '/tmp/orbit-current.tar.gz');
    } finally {
        umask($previousUmask);
        @unlink($source);
    }

    expect($pushedMode)->toBe('644')
        ->and($commands[0])->toContain("incus file push '/tmp/orbit-current-transfer-")
        ->and($commands[1])->toContain("rm -f '/tmp/orbit-current-transfer-");
});

it('allows remote checkout archive copies to use ssh agent identities', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-incus-source-');
    file_put_contents($source, 'archive');

    $scpCommand = null;
    Process::fake(function ($process) use (&$scpCommand) {
        $scpCommand = $process->command;

        return Process::result();
    });
    Process::preventStrayProcesses();

    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(host: 'beast'), $commands);
    $instance = new IncusInstance($host, 'orbit-template-operator');

    try {
        $instance->copyLocalFileToInstance($source, '/tmp/orbit-current.tar.gz');
    } finally {
        @unlink($source);
    }

    expect($scpCommand)->toContain('scp -o BatchMode=yes')
        ->and($scpCommand)->not->toContain('IdentitiesOnly=yes')
        ->and($scpCommand)->toContain("'beast':")
        ->and($commands[0])->toContain("incus file push '/tmp/orbit-current-transfer-")
        ->and($commands[1])->toContain("rm -f '/tmp/orbit-current-transfer-");
});

it('stages local Docker image archives in the pushed provisioning bundle when available on the Incus host', function (): void {
    $localBundle = sys_get_temp_dir().'/orbit-incus-local-bundle-'.bin2hex(random_bytes(4));
    $remoteStage = sys_get_temp_dir().'/orbit-incus-remote-stage-'.bin2hex(random_bytes(4));
    mkdir($localBundle, 0755, true);
    file_put_contents("{$localBundle}/orbit-source.tar.gz", 'source');

    $commands = [];
    $host = new class(incusHostTestConfig(host: 'localhost'), $commands, $remoteStage) extends IncusHost
    {
        /** @var list<string> */
        private array $commands;

        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, array &$commands, private string $remoteStage)
        {
            parent::__construct($config);
            $this->commands = &$commands;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (str_contains($command, 'mktemp -d')) {
                mkdir($this->remoteStage, 0755, true);

                return incusHostTestProcessResult($this->remoteStage."\n");
            }

            return incusHostTestProcessResult();
        }
    };

    try {
        $remoteBundle = $host->pushBundle($localBundle);
    } finally {
        (new Symfony\Component\Process\Process(['rm', '-rf', $localBundle, $remoteStage]))->run();
    }

    $commandOutput = implode("\n", $commands);

    expect($remoteBundle)->toBe("{$remoteStage}/orbit-e2e-bundle")
        ->and($commands)->toContain('mktemp -d /tmp/orbit-e2e-stage-XXXXXX')
        ->and($commandOutput)->toContain("docker image inspect 'orbit-gateway:prepared-current'")
        ->and($commandOutput)->not->toContain("docker pull 'orbit-gateway:prepared-current'")
        ->and($commandOutput)->toContain("docker save 'orbit-gateway:prepared-current'")
        ->and($commandOutput)->toContain("'{$remoteStage}/orbit-e2e-bundle/orbit-gateway-current.tar'")
        ->and($commandOutput)->toContain("docker image inspect 'caddy:2-alpine'")
        ->and($commandOutput)->toContain("docker pull 'caddy:2-alpine'")
        ->and($commandOutput)->toContain("docker save 'caddy:2-alpine'")
        ->and($commandOutput)->toContain("'{$remoteStage}/orbit-e2e-bundle/caddy-2-alpine.tar'")
        ->and($commandOutput)->toContain("docker image inspect '4km3/dnsmasq:latest'")
        ->and($commandOutput)->toContain("docker pull '4km3/dnsmasq:latest'")
        ->and($commandOutput)->toContain("docker save '4km3/dnsmasq:latest'")
        ->and($commandOutput)->toContain("'{$remoteStage}/orbit-e2e-bundle/dnsmasq-latest.tar'")
        ->and($commandOutput)->toContain("docker image inspect 'dunglas/frankenphp:1-php8.5-bookworm'")
        ->and($commandOutput)->toContain("docker pull 'dunglas/frankenphp:1-php8.5-bookworm'")
        ->and($commandOutput)->toContain("docker save 'dunglas/frankenphp:1-php8.5-bookworm'")
        ->and($commandOutput)->toContain("'{$remoteStage}/orbit-e2e-bundle/frankenphp-1-php8.5-bookworm.tar'")
        ->and($commandOutput)->toContain("docker image inspect 'orbit-reverb:current'")
        ->and($commandOutput)->toContain("docker build --pull=false -t 'orbit-reverb:current'")
        ->and($commandOutput)->toContain("docker save 'orbit-reverb:current'")
        ->and($commandOutput)->toContain("'{$remoteStage}/orbit-e2e-bundle/orbit-reverb-current.tar'")
        ->and($commandOutput)->toContain("docker image inspect 'ghcr.io/wg-easy/wg-easy:15'")
        ->and($commandOutput)->toContain("docker pull 'ghcr.io/wg-easy/wg-easy:15'")
        ->and($commandOutput)->toContain("docker save 'ghcr.io/wg-easy/wg-easy:15'")
        ->and($commandOutput)->toContain("'{$remoteStage}/orbit-e2e-bundle/wg-easy-15.tar'");
});

it('materializes a namespaced gateway image archive from the prepared fallback image', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Provision Serving',
    ], function (): void {
        $localBundle = sys_get_temp_dir().'/orbit-incus-local-bundle-'.bin2hex(random_bytes(4));
        $remoteStage = sys_get_temp_dir().'/orbit-incus-remote-stage-'.bin2hex(random_bytes(4));
        mkdir($localBundle, 0755, true);
        file_put_contents("{$localBundle}/orbit-source.tar.gz", 'source');

        $commands = [];
        $host = new class(incusHostTestConfig(host: 'localhost'), $commands, $remoteStage) extends IncusHost
        {
            /** @var list<string> */
            private array $commands;

            /**
             * @param  list<string>  $commands
             */
            public function __construct(E2EConfig $config, array &$commands, private string $remoteStage)
            {
                parent::__construct($config);
                $this->commands = &$commands;
            }

            public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
            {
                $this->commands[] = $command;

                if (str_contains($command, 'mktemp -d')) {
                    mkdir($this->remoteStage, 0755, true);

                    return incusHostTestProcessResult($this->remoteStage."\n");
                }

                return incusHostTestProcessResult();
            }
        };

        try {
            $host->pushBundle($localBundle);
        } finally {
            (new Symfony\Component\Process\Process(['rm', '-rf', $localBundle, $remoteStage]))->run();
        }

        $commandOutput = implode("\n", $commands);

        expect($commandOutput)
            ->toContain("docker image inspect 'orbit-gateway:provision-serving-current'")
            ->toContain("docker image inspect 'orbit-gateway:prepared-current'")
            ->toContain("docker tag 'orbit-gateway:prepared-current' 'orbit-gateway:provision-serving-current'")
            ->toContain("docker save 'orbit-gateway:provision-serving-current'")
            ->toContain("'{$remoteStage}/orbit-e2e-bundle/orbit-gateway-current.tar'");
    });
});

it('passes staged Docker image archives to the in-guest provisioner when present', function (): void {
    $commands = [];
    $host = new class(incusHostTestConfig(), $commands) extends IncusHost
    {
        /** @var list<string> */
        private array $commands;

        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, array &$commands)
        {
            parent::__construct($config);
            $this->commands = &$commands;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (str_contains($command, '/composer-cache')) {
                return incusHostTestProcessResult(exitCode: 1);
            }

            return incusHostTestProcessResult();
        }
    };

    $host->provisionInstance('orbit-e2e-run-gateway', 'gateway', '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle', 'orbit');

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)
        ->toContain("test -f '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle/orbit-gateway-current.tar'")
        ->toContain("test -f '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle/caddy-2-alpine.tar'")
        ->toContain("test -f '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle/dnsmasq-latest.tar'")
        ->toContain("test -f '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle/frankenphp-1-php8.5-bookworm.tar'")
        ->toContain("test -f '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle/wg-easy-15.tar'")
        ->toContain("incus file push -r -p '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle' 'orbit-e2e-run-gateway/var/tmp/'")
        ->toContain('--node-kind=')
        ->toContain('--gateway-image=orbit-gateway:prepared-current')
        ->toContain('--gateway-image-archive=/var/tmp/orbit-e2e-bundle/orbit-gateway-current.tar')
        ->toContain('--caddy-image-archive=/var/tmp/orbit-e2e-bundle/caddy-2-alpine.tar')
        ->toContain('--dnsmasq-image-archive=/var/tmp/orbit-e2e-bundle/dnsmasq-latest.tar')
        ->toContain('--frankenphp-image-archive=/var/tmp/orbit-e2e-bundle/frankenphp-1-php8.5-bookworm.tar')
        ->toContain('--wg-easy-image-archive=/var/tmp/orbit-e2e-bundle/wg-easy-15.tar')
        ->toContain('--operator-user=');
});

it('passes a staged Composer cache to the in-guest provisioner when present', function (): void {
    $commands = [];
    $host = new class(incusHostTestConfig(), $commands) extends IncusHost
    {
        /** @var list<string> */
        private array $commands;

        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, array &$commands)
        {
            parent::__construct($config);
            $this->commands = &$commands;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return incusHostTestProcessResult();
        }
    };

    $host->provisionInstance('orbit-e2e-run-gateway', 'gateway', '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle', 'orbit');

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)
        ->toContain("test -d '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle/composer-cache'")
        ->toContain('--composer-cache=/var/tmp/orbit-e2e-bundle/composer-cache');
});

it('does not pass the wg-easy image archive to non-gateway in-guest provisioning', function (): void {
    $commands = [];
    $host = new class(incusHostTestConfig(), $commands) extends IncusHost
    {
        /** @var list<string> */
        private array $commands;

        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, array &$commands)
        {
            parent::__construct($config);
            $this->commands = &$commands;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (str_contains($command, '/composer-cache')) {
                return incusHostTestProcessResult(exitCode: 1);
            }

            return incusHostTestProcessResult();
        }
    };

    $host->provisionInstance('orbit-e2e-run-app', 'app', '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle', 'orbit');

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)
        ->toContain('--frankenphp-image-archive=/var/tmp/orbit-e2e-bundle/frankenphp-1-php8.5-bookworm.tar')
        ->toContain('--binary=/var/tmp/orbit-e2e-bundle/orbit-binary')
        ->not->toContain('--source-archive=/var/tmp/orbit-e2e-bundle/orbit-source.tar.gz')
        ->not->toContain('orbit-gateway-current.tar')
        ->not->toContain('--gateway-image-archive=')
        ->not->toContain('wg-easy-15.tar')
        ->not->toContain('--wg-easy-image-archive=');
});

it('can restore snapshots concurrently', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->restoreSnapshotsConcurrently([
        'orbit-e2e-run-operator',
        'orbit-e2e-run-gateway',
    ], 'lease-warm', stateful: true);

    expect($commands[0])->toContain("incus snapshot restore 'orbit-e2e-run-operator' 'lease-warm' --stateful & PID_RESTORE_0=$!")
        ->and($commands[0])->toContain("incus snapshot restore 'orbit-e2e-run-gateway' 'lease-warm' --stateful & PID_RESTORE_1=$!")
        ->and($commands[0])->toContain('wait $PID_RESTORE_0')
        ->and($commands[0])->toContain('wait $PID_RESTORE_1');
});
