<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Vpn\WgEasyServiceInstaller;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->workdir = sys_get_temp_dir().'/orbit-wg-easy-installer-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->workdir);
    $this->statePath = $this->workdir.'/.wg-easy';

    config()->set('orbit.operation_token_ttl_seconds', 120);

    $this->vpnNode = Node::factory()->create([
        'name' => 'gateway-1',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->for($this->vpnNode)->create([
        'role' => 'vpn',
        'status' => 'active',
    ]);

    $this->wgEasyStateTransport = new WgEasyServiceInstallerStateTransport(
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success(['updated' => true]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            stderr: '',
            durationMs: 1,
        ),
    );

    app()->instance(RemoteLocalExecutor::class, wgEasyServiceInstallerExecutor($this->wgEasyStateTransport));
});

afterEach(function (): void {
    if (isset($this->workdir) && is_string($this->workdir) && is_dir($this->workdir)) {
        File::deleteDirectory($this->workdir);
    }
});

it('renders the wg-easy compose file with the configured runtime envs', function (): void {
    Process::fake();

    $installer = wgEasyServiceInstaller($this->workdir, $this->statePath);

    $installer->install(
        publicHost: '203.0.113.10',
        username: 'orbit',
        password: 'secret-password',
        wireguardCidr: '10.7.0.0/24',
        wireguardPort: 51830,
        dnsIp: '10.7.0.1',
    );

    $composePath = $this->workdir.'/wg-easy/docker-compose.yaml';
    $compose = File::get($composePath);

    expect($compose)->toContain('INIT_ENABLED=true')
        ->and($compose)->toContain('INIT_USERNAME=orbit')
        ->and($compose)->toContain('INIT_PASSWORD=secret-password')
        ->and($compose)->toContain('INIT_HOST=203.0.113.10')
        ->and($compose)->toContain('INIT_PORT=51830')
        ->and($compose)->toContain('INIT_DNS=10.7.0.1')
        ->and($compose)->toContain('INIT_ALLOWED_IPS=10.7.0.0/24')
        ->and($compose)->toContain('INSECURE=true')
        ->and($compose)->toContain('DISABLE_IPV6=true')
        ->and($compose)->toContain('51830:51830/udp')
        ->and($compose)->toContain('127.0.0.1:51821:51821/tcp')
        ->and($compose)->toContain('NET_ADMIN')
        ->and($compose)->toContain('SYS_MODULE');
});

it('defaults the wg-easy database path to the managed orbit home', function (): void {
    expect(config('services.wg_easy.database_path'))->toBe('/home/orbit/.wg-easy/wg-easy.db');
});

it('checks wg-easy readiness through the container filesystem when host state is not mounted', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        if (str_contains((string) $process->command, "test -f '/home/orbit/.wg-easy'/wg-easy.db")) {
            return Process::result(exitCode: 1);
        }

        if (str_contains((string) $process->command, 'wg show wg0 public-key')) {
            return Process::result(output: "wg-easy-public-key\n");
        }

        return Process::result();
    });

    $publicKey = wgEasyServiceInstaller($this->workdir, '/home/orbit/.wg-easy')->publicKey();

    $readinessCommand = collect($commands)->first(fn (string $command): bool => str_contains($command, 'ip link show wg0'));

    expect($publicKey)->toBe('wg-easy-public-key')
        ->and($readinessCommand)->toBeString()
        ->toContain('$ORBIT_DOCKER exec wg-easy test -f /etc/wireguard/wg-easy.db')
        ->not->toContain("test -f '/home/orbit/.wg-easy'/wg-easy.db");
});

it('routes peer persistence through the local executor when resolved from the container', function (): void {
    $previousServerHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = '/var/www';

    config()->set('services.wg_easy.database_path', '/home/orbit/.wg-easy/wg-easy.db');
    app()->forgetInstance(WgEasyServiceInstaller::class);

    Process::fake();

    try {
        app(WgEasyServiceInstaller::class)->configurePeers([
            [
                'name' => 'app-dev-1',
                'private_key' => 'app-dev-private',
                'public_key' => 'app-dev-public',
                'pre_shared_key' => 'app-dev-psk',
                'address' => '10.6.0.4',
            ],
        ]);
    } finally {
        if ($previousServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $previousServerHome;
        }

        app()->forgetInstance(WgEasyServiceInstaller::class);
    }

    $scripts = array_column($this->wgEasyStateTransport->calls, 'script');

    expect($scripts)->toHaveCount(2)
        ->and($scripts[0])->toContain('internal:wg-easy:state')
        ->and($scripts[0])->toContain("--action='delete-peer'")
        ->and($scripts[1])->toContain("--action='upsert-peer'");

    foreach ($scripts as $script) {
        expect($script)->not->toContain('sqlite3')
            ->and($script)->not->toContain('sudo sqlite3')
            ->and($script)->not->toContain('/var/www/.wg-easy');
    }
});

it('uses the default runtime values when install inputs are omitted', function (): void {
    Process::fake();

    $installer = wgEasyServiceInstaller($this->workdir, $this->statePath);

    $installer->install(publicHost: '203.0.113.10', username: 'orbit', password: 'secret-password');

    $compose = File::get($this->workdir.'/wg-easy/docker-compose.yaml');

    expect($compose)->toContain('INIT_PORT=51820')
        ->and($compose)->toContain('INIT_DNS=10.6.0.1')
        ->and($compose)->toContain('INIT_ALLOWED_IPS=10.6.0.0/24')
        ->and($compose)->toContain('51820:51820/udp');
});

it('invokes docker compose up to start the wg-easy container', function (): void {
    Process::fake();

    wgEasyServiceInstaller($this->workdir, $this->statePath)
        ->install(publicHost: '203.0.113.10', username: 'orbit', password: 'secret-password');

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, '$ORBIT_DOCKER compose')
        && str_contains((string) $process->command, 'up -d'));
});

it('reads the wg-easy server public key from the running container', function (): void {
    Process::fake(function ($process) {
        if (str_contains((string) $process->command, 'wg show wg0 public-key')) {
            return Process::result(output: "wg-easy-public-key\n");
        }

        return Process::result();
    });

    $publicKey = wgEasyServiceInstaller($this->workdir, $this->statePath)->publicKey();

    expect($publicKey)->toBe('wg-easy-public-key');
});

it('persists and activates node peers on wg-easy wg0', function (): void {
    $runtimeScript = null;

    Process::fake(function ($process) use (&$runtimeScript) {
        if (str_contains((string) $process->command, 'wg set wg0 peer')) {
            $runtimeScript = (string) $process->command;
        }

        return Process::result();
    });

    wgEasyServiceInstaller($this->workdir, $this->statePath)->configurePeers([
        [
            'name' => 'gateway-1',
            'private_key' => 'gateway-private',
            'public_key' => 'gateway-public',
            'pre_shared_key' => 'gateway-psk',
            'address' => '10.6.0.2',
        ],
        [
            'name' => 'control-1',
            'private_key' => 'control-private',
            'public_key' => 'control-public',
            'pre_shared_key' => 'control-psk',
            'address' => '10.6.0.3',
        ],
    ]);

    $scripts = array_column($this->wgEasyStateTransport->calls, 'script');

    expect($scripts)->toHaveCount(4)
        ->and($scripts[0])->toContain("--action='delete-peer'")
        ->and($scripts[0])->toContain("--name='gateway-1'")
        ->and($scripts[1])->toContain("--action='upsert-peer'")
        ->and($scripts[1])->toContain("--name='gateway-1'")
        ->and($scripts[1])->toContain("--ipv4='10.6.0.2'")
        ->and($scripts[1])->toContain("--ipv6='fdcc:ad94:bacf:61a4::cafe:2'")
        ->and($scripts[1])->toContain("--private-key='gateway-private'")
        ->and($scripts[1])->toContain("--public-key='gateway-public'")
        ->and($scripts[1])->toContain("--pre-shared-key='gateway-psk'")
        ->and($scripts[2])->toContain("--action='delete-peer'")
        ->and($scripts[2])->toContain("--name='control-1'")
        ->and($scripts[3])->toContain("--action='upsert-peer'")
        ->and($scripts[3])->toContain("--name='control-1'")
        ->and($scripts[3])->toContain("--ipv4='10.6.0.3'")
        ->and($scripts[3])->toContain("--public-key='control-public'")
        ->and($scripts[3])->toContain("--pre-shared-key='control-psk'");

    foreach ($scripts as $script) {
        expect($script)->toContain('internal:wg-easy:state')
            ->and($script)->toContain('--operation-token=')
            ->and($script)->not->toContain('sqlite3')
            ->and($script)->not->toContain('sudo sqlite3')
            ->and($script)->not->toContain('clients_table');
    }

    $dispatching = wgEasyServiceInstallerLocalExecutorDispatchingProperties();
    $upsertLogs = [$dispatching[1], $dispatching[3]];

    expect($dispatching)->toHaveCount(4)
        ->and($dispatching[1]['command_options']['private-key'])->toBe('<redacted>')
        ->and($dispatching[1]['command_options']['pre-shared-key'])->toBe('<redacted>')
        ->and($dispatching[1]['command_line'])->toContain('--private-key=<redacted>')
        ->and($dispatching[1]['command_line'])->toContain('--pre-shared-key=<redacted>')
        ->and($dispatching[3]['command_options']['private-key'])->toBe('<redacted>')
        ->and($dispatching[3]['command_options']['pre-shared-key'])->toBe('<redacted>')
        ->and($dispatching[3]['command_line'])->toContain('--private-key=<redacted>')
        ->and($dispatching[3]['command_line'])->toContain('--pre-shared-key=<redacted>')
        ->and(json_encode($upsertLogs, JSON_THROW_ON_ERROR))->not->toContain('gateway-private')
        ->and(json_encode($upsertLogs, JSON_THROW_ON_ERROR))->not->toContain('gateway-psk')
        ->and(json_encode($upsertLogs, JSON_THROW_ON_ERROR))->not->toContain('control-private')
        ->and(json_encode($upsertLogs, JSON_THROW_ON_ERROR))->not->toContain('control-psk');

    expect($runtimeScript)->toContain('ORBIT_DOCKER="sudo docker"')
        ->and($runtimeScript)->toContain('set -eu')
        ->and($runtimeScript)->not->toContain('set -euo pipefail')
        ->and($runtimeScript)->toContain('gateway-public')
        ->and($runtimeScript)->toContain('gateway-psk')
        ->and($runtimeScript)->toContain('10.6.0.2/32')
        ->and($runtimeScript)->toContain('control-public')
        ->and($runtimeScript)->toContain('control-psk')
        ->and($runtimeScript)->toContain('10.6.0.3/32')
        ->and($runtimeScript)->toContain('wg set wg0 peer')
        ->and($runtimeScript)->toContain('preshared-key');
});

it('does not leak peer secrets from transport exception messages during peer upsert', function (): void {
    Process::fake();

    $privateKey = 'SENTINEL-PRIVKEY-XYZ';
    $preSharedKey = 'SENTINEL-PSK-ABC';
    $transport = new WgEasyServiceInstallerStateTransport(
        static function (Node $node, string $script, array $options): RemoteShellResult {
            if (str_contains($script, "--action='upsert-peer'")) {
                throw new RuntimeException("transport failed while running {$script}");
            }

            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(JsonEnvelope::success(['updated' => true]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                stderr: '',
                durationMs: 1,
            );
        },
    );
    app()->instance(RemoteLocalExecutor::class, wgEasyServiceInstallerExecutor($transport));

    try {
        wgEasyServiceInstaller($this->workdir, $this->statePath)->configurePeers([
            [
                'name' => 'gateway-1',
                'private_key' => $privateKey,
                'public_key' => 'gateway-public',
                'pre_shared_key' => $preSharedKey,
                'address' => '10.6.0.2',
            ],
        ]);

        $this->fail('Expected wg-easy peer upsert transport failure.');
    } catch (RuntimeException $exception) {
        $completed = wgEasyServiceInstallerLocalExecutorCompletedProperties();
        $failed = array_values(array_filter(
            $completed,
            fn (array $properties): bool => ($properties['status'] ?? null) === 'failed',
        ));

        expect($exception->getMessage())->not->toContain($privateKey)
            ->and($exception->getMessage())->not->toContain($preSharedKey)
            ->and($completed)->toHaveCount(2)
            ->and($failed)->toHaveCount(1)
            ->and($failed[0]['exception_message'])->toContain('--private-key=<redacted>')
            ->and($failed[0]['exception_message'])->toContain('--pre-shared-key=<redacted>')
            ->and($failed[0]['exception_message'])->not->toContain($privateKey)
            ->and($failed[0]['exception_message'])->not->toContain($preSharedKey);
    }
});

it('converges the runtime server address and routes supported database updates through the local executor', function (): void {
    $serverAddressScript = null;

    Process::fake(function ($process) use (&$serverAddressScript) {
        if (str_contains((string) $process->command, 'ip addr replace')) {
            $serverAddressScript = (string) $process->command;
        }

        return Process::result();
    });

    wgEasyServiceInstaller($this->workdir, $this->statePath)->install(
        publicHost: 'vpn.example.com',
        username: 'orbit',
        password: 'secret-password',
        wireguardCidr: '10.7.0.0/24',
        wireguardPort: 51830,
        dnsIp: '10.7.0.1',
    );

    expect($serverAddressScript)->toContain("ip addr replace '10.7.0.1/24' dev wg0")
        ->and($serverAddressScript)->toContain("ip route replace '10.7.0.0/24' dev wg0")
        ->and($serverAddressScript)->not->toContain('sqlite3')
        ->and($serverAddressScript)->not->toContain('sudo sqlite3')
        ->and($serverAddressScript)->not->toContain('interfaces_table')
        ->and($serverAddressScript)->not->toContain('ipv4_cidr')
        ->and($serverAddressScript)->not->toContain('user_configs_table')
        ->and($serverAddressScript)->not->toContain('general_table')
        ->and($serverAddressScript)->not->toContain('default_dns')
        ->and($serverAddressScript)->not->toContain('setup_step');

    $scripts = array_column($this->wgEasyStateTransport->calls, 'script');
    $metadata = array_column(array_column($this->wgEasyStateTransport->calls, 'options'), 'metadata');

    expect($scripts)->toHaveCount(4)
        ->and($scripts[0])->toContain('internal:wg-easy:state')
        ->and($scripts[0])->toContain("--action='ensure-writable'")
        ->and($scripts[0])->toContain('--operation-token=')
        ->and($scripts[1])->toContain("--action='update-interface'")
        ->and($scripts[1])->toContain("--ipv4-cidr='10.7.0.0/24'")
        ->and($scripts[2])->toContain("--action='update-user'")
        ->and($scripts[2])->toContain("--host='vpn.example.com'")
        ->and($scripts[2])->toContain("--default-dns='[\"10.7.0.1\"]'")
        ->and($scripts[2])->toContain("--default-persistent-keepalive='25'")
        ->and($scripts[3])->toContain("--action='update-general'")
        ->and($scripts[3])->toContain("--setup-step='0'");

    foreach ($metadata as $entry) {
        expect($entry)->toBeArray()
            ->and($entry['ORBIT_WG_EASY_DB_PATH'] ?? null)->toBe($this->statePath.'/wg-easy.db');
    }

    foreach ($scripts as $script) {
        expect($script)->not->toContain('sqlite3')
            ->and($script)->not->toContain('sudo sqlite3');
    }
});

it('is idempotent: rerunning with same inputs does not recreate compose file unnecessarily', function (): void {
    Process::fake();

    $installer = wgEasyServiceInstaller($this->workdir, $this->statePath);

    $installer->install(publicHost: '203.0.113.10', username: 'orbit', password: 'secret-password');
    $composePath = $this->workdir.'/wg-easy/docker-compose.yaml';
    $firstMtime = filemtime($composePath);

    // Backdate the file so a second write would land on a different mtime
    // (cheaper than `sleep(1)` while still detecting unnecessary rewrites).
    touch($composePath, $firstMtime - 60);
    $expectedMtime = filemtime($composePath);
    clearstatcache();

    $installer->install(publicHost: '203.0.113.10', username: 'orbit', password: 'secret-password');
    $secondMtime = filemtime($composePath);

    expect($secondMtime)->toBe($expectedMtime);
});

it('rejects invalid public host', function (): void {
    expect(fn (): mixed => wgEasyServiceInstaller($this->workdir)
        ->install(publicHost: '', username: 'orbit', password: 'secret-password'))
        ->toThrow(RuntimeException::class);
});

it('rejects empty username', function (): void {
    expect(fn (): mixed => wgEasyServiceInstaller($this->workdir)
        ->install(publicHost: '203.0.113.10', username: '', password: 'secret-password'))
        ->toThrow(RuntimeException::class);
});

it('rejects empty password', function (): void {
    expect(fn (): mixed => wgEasyServiceInstaller($this->workdir)
        ->install(publicHost: '203.0.113.10', username: 'orbit', password: ''))
        ->toThrow(RuntimeException::class);
});

it('raises a generic exception when wg-easy state output is not parseable JSON', function (): void {
    $secret = 'remote-output-secret';
    $transport = new WgEasyServiceInstallerStateTransport(new RemoteShellResult(
        exitCode: 1,
        stdout: "not-json {$secret}",
        stderr: "stderr {$secret}",
        durationMs: 1,
    ));

    app()->instance(RemoteLocalExecutor::class, wgEasyServiceInstallerExecutor($transport));
    Process::fake();

    try {
        wgEasyServiceInstaller($this->workdir, $this->statePath)
            ->install(publicHost: 'vpn.example.com', username: 'orbit', password: 'secret-password');

        $this->fail('Expected wg-easy state parsing to fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Failed to verify wg-easy state writability.')
            ->and($exception->getMessage())->not->toContain($secret)
            ->and($exception->getMessage())->not->toContain('not-json');
    }
});

it('only exposes whitelisted wg-easy state error codes from remote failure envelopes', function (
    string $remoteCode,
    bool $shouldExposeCode,
): void {
    $secret = 'remote-message-secret';
    $transport = new WgEasyServiceInstallerStateTransport(new RemoteShellResult(
        exitCode: 1,
        stdout: json_encode([
            'error' => [
                'code' => $remoteCode,
                'message' => "remote leak {$secret}",
            ],
            'meta' => [
                'raw' => $secret,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        stderr: "stderr {$secret}",
        durationMs: 1,
    ));

    app()->instance(RemoteLocalExecutor::class, wgEasyServiceInstallerExecutor($transport));
    Process::fake();

    try {
        wgEasyServiceInstaller($this->workdir, $this->statePath)
            ->install(publicHost: 'vpn.example.com', username: 'orbit', password: 'secret-password');

        $this->fail('Expected wg-easy state failure.');
    } catch (RuntimeException $exception) {
        $meta = wgEasyServiceInstallerExceptionMeta($exception);

        expect($exception->getMessage())->toBe('Failed to verify wg-easy state writability.')
            ->and($exception->getMessage())->not->toContain($secret)
            ->and($exception->getMessage())->not->toContain('remote leak')
            ->and($exception->getMessage())->not->toContain($remoteCode);

        if ($shouldExposeCode) {
            expect($meta)->toHaveKey('wg_easy_state_error_code', $remoteCode);
        } else {
            expect($meta)->not->toHaveKey('wg_easy_state_error_code');
        }
    }
})->with([
    'whitelisted database_missing' => ['database_missing', true],
    'unknown code' => ['remote_secret_code', false],
]);

/**
 * @return array<string, mixed>
 */
function wgEasyServiceInstallerExceptionMeta(Throwable $exception): array
{
    if (! property_exists($exception, 'meta')) {
        return [];
    }

    $meta = $exception->meta;

    return is_array($meta) ? $meta : [];
}

/**
 * @return list<array<string, mixed>>
 */
function wgEasyServiceInstallerLocalExecutorDispatchingProperties(): array
{
    return DB::table('activity_log')
        ->where('log_name', 'local_executor')
        ->where('event', 'local_executor.dispatching')
        ->orderBy('id')
        ->get()
        ->map(fn (object $activity): array => json_decode((string) $activity->properties, true, flags: JSON_THROW_ON_ERROR))
        ->all();
}

/**
 * @return list<array<string, mixed>>
 */
function wgEasyServiceInstallerLocalExecutorCompletedProperties(): array
{
    return DB::table('activity_log')
        ->where('log_name', 'local_executor')
        ->where('event', 'local_executor.completed')
        ->orderBy('id')
        ->get()
        ->map(fn (object $activity): array => json_decode((string) $activity->properties, true, flags: JSON_THROW_ON_ERROR))
        ->all();
}

function wgEasyServiceInstaller(string $rootPath, ?string $statePath = null): WgEasyServiceInstaller
{
    return new WgEasyServiceInstaller(
        rootPath: $rootPath,
        statePath: $statePath,
        localExecutor: app(RemoteLocalExecutor::class),
    );
}

function wgEasyServiceInstallerExecutor(WgEasyServiceInstallerStateTransport $transport): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        transport: $transport,
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: 'gateway-secret',
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        operationTokenSecret: 'gateway-secret',
    );
}

final class WgEasyServiceInstallerStateTransport implements RemoteExecutor
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    /**
     * @param  RemoteShellResult|Closure(Node, string, array<string, mixed>): RemoteShellResult  $result
     */
    public function __construct(
        private readonly RemoteShellResult|Closure $result,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node,
            'script' => $script,
            'options' => $options,
        ];

        if ($this->result instanceof Closure) {
            return ($this->result)($node, $script, $options);
        }

        return $this->result;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('The recording transport does not start processes.');
    }
}
