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
use App\Services\Vpn\VpnNodeResolver;
use App\Services\Vpn\WgEasyVpnBackend;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('mints client configs with the wireguard server dns address', function (): void {
    config()->set('services.wg_easy.username', 'orbit');
    config()->set('services.wg_easy.password', 'secret-password');

    Http::preventStrayRequests();

    $clientListCalls = 0;

    Http::fake(function (Request $request) use (&$clientListCalls) {
        if ($request->url() === 'http://127.0.0.1:51821/api/session') {
            return Http::response(['status' => 'success'], 200, [
                'Set-Cookie' => 'wg-easy=session-token; Path=/; HttpOnly',
            ]);
        }

        if ($request->method() === 'GET' && $request->url() === 'http://127.0.0.1:51821/api/client') {
            $clientListCalls++;

            return Http::response($clientListCalls === 1 ? [] : [
                [
                    'id' => 'client-7',
                    'name' => 'laptop',
                    'ipv4Address' => '10.6.0.7',
                    'enabled' => true,
                    'latestHandshakeAt' => null,
                ],
            ], 200);
        }

        if ($request->method() === 'POST' && $request->url() === 'http://127.0.0.1:51821/api/client') {
            return Http::response(['id' => 'client-7'], 200);
        }

        if ($request->url() === 'http://127.0.0.1:51821/api/client/client-7/configuration') {
            return Http::response(implode("\n", [
                '[Interface]',
                'PrivateKey = client-private',
                'Address = 10.6.0.7/32',
                'DNS = 10.6.0.2, 1.1.1.1, bear, gateway',
                '',
                '[Peer]',
                'PublicKey = server-public',
                'AllowedIPs = 0.0.0.0/0',
                'Endpoint = vpn.example.com:51820',
                '',
            ]), 200);
        }

        return Http::response("Unexpected request {$request->method()} {$request->url()}", 500);
    });

    $client = WgEasyVpnBackend::fromConfig()->createClient('laptop', includeConfig: true);

    expect($client->config)
        ->toContain('DNS = 10.6.0.1')
        ->not->toContain('10.6.0.2')
        ->not->toContain('1.1.1.1')
        ->not->toContain('bear')
        ->not->toContain('gateway');
});

it('routes password and session secret updates through wg-easy state actions with redacted output summaries', function (): void {
    $node = Node::factory()->create([
        'name' => 'gateway-1',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);

    $hash = '$argon2id$v=19$m=65536,t=3,p=4$hash$hash';

    NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'vpn',
        'status' => 'active',
    ]);

    $transport = new WgEasyVpnBackendStateTransport(
        static function (Node $node, string $script, array $options) use ($hash): RemoteShellResult {
            $action = match (true) {
                str_contains($script, 'update-user-password') => 'update-user-password',
                str_contains($script, 'update-session-password') => 'update-session-password',
                default => 'ensure-writable',
            };

            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(JsonEnvelope::success([
                    'action' => $action,
                    'probe' => $hash,
                ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                stderr: "stderr {$hash}",
                durationMs: 1,
            );
        },
    );

    Http::fake([
        'http://127.0.0.1:51821/api/session' => Http::response(['status' => 'success'], 200, [
            'Set-Cookie' => 'wg-easy=session-token; Path=/; HttpOnly',
        ]),
        'http://127.0.0.1:51821/api/client' => Http::response([], 200),
    ]);

    Process::fake(function ($process) {
        $command = (string) $process->command;

        if (str_contains($command, 'docker exec -i -w /app/server wg-easy node')) {
            return Process::result('$argon2id$v=19$m=65536,t=3,p=4$hash$hash');
        }

        return Process::result();
    });

    $result = (new WgEasyVpnBackend(
        username: 'orbit',
        password: 'current-secret-password',
        localExecutor: wgEasyVpnBackendExecutor($transport),
        vpnNodeResolver: app(VpnNodeResolver::class),
    ))
        ->changeWebUiPassword('new-secret-password');

    expect($result->passwordChanged)->toBeTrue()
        ->and($result->sessionsInvalidated)->toBeTrue();

    $scripts = array_column($transport->calls, 'script');

    expect($scripts)->toHaveCount(3)
        ->and($scripts[0])->toContain('internal:wg-easy:state')
        ->and($scripts[0])->toContain("--action='ensure-writable'")
        ->and($scripts[0])->toContain('--operation-token=')
        ->and($scripts[1])->toContain("--action='update-user-password'")
        ->and($scripts[1])->toContain("--password-hash='{$hash}'")
        ->and($scripts[2])->toContain("--action='update-session-password'")
        ->and($scripts[2])->toContain("--password-hash='{$hash}'");

    foreach ($scripts as $script) {
        expect($script)->not->toContain('sqlite3')
            ->and($script)->not->toContain('sudo sqlite3');
    }

    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'sqlite3'));

    $dispatching = wgEasyVpnBackendLocalExecutorDispatchingProperties();
    $completed = wgEasyVpnBackendLocalExecutorCompletedProperties();
    $passwordActionLogs = [$dispatching[1], $dispatching[2]];

    expect($dispatching)->toHaveCount(3)
        ->and($dispatching[1]['command_options']['password-hash'])->toBe('<redacted>')
        ->and($dispatching[1]['command_line'])->toContain('--password-hash=<redacted>')
        ->and($dispatching[2]['command_options']['password-hash'])->toBe('<redacted>')
        ->and($dispatching[2]['command_line'])->toContain('--password-hash=<redacted>')
        ->and(json_encode($passwordActionLogs, JSON_THROW_ON_ERROR))->not->toContain($hash)
        ->and(json_encode($passwordActionLogs, JSON_THROW_ON_ERROR))->not->toContain('new-secret-password')
        ->and($completed)->toHaveCount(3)
        ->and($completed[1]['stdout_summary'])->toBe('<suppressed>')
        ->and($completed[1]['stderr_summary'])->toBe('<suppressed>')
        ->and($completed[2]['stdout_summary'])->toBe('<suppressed>')
        ->and($completed[2]['stderr_summary'])->toBe('<suppressed>')
        ->and(json_encode([$completed[1], $completed[2]], JSON_THROW_ON_ERROR))->not->toContain($hash)
        ->and(json_encode([$completed[1], $completed[2]], JSON_THROW_ON_ERROR))->not->toContain('new-secret-password');
});

it('writes WG_EASY_PASSWORD to the active gateway environment file instead of the source tree env', function (): void {
    $transport = new WgEasyVpnBackendStateTransport(
        static fn (Node $node, string $script, array $options): RemoteShellResult => new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success(['updated' => true]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            stderr: '',
            durationMs: 1,
        ),
    );

    $envRoot = sys_get_temp_dir().'/wg-easy-env-root-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($envRoot);
    File::put($envRoot.'/.env.runtime', "APP_NAME=Orbit\n");

    $app = app();
    $originalEnvironmentPath = $app->environmentPath();
    $originalEnvironmentFile = $app->environmentFile();

    $app->useEnvironmentPath($envRoot);
    $app->loadEnvironmentFrom('.env.runtime');

    try {
        wgEasyVpnBackendReadyForPasswordRotation($transport)
            ->changeWebUiPassword('new-secret-password');

        expect(File::get($envRoot.'/.env.runtime'))
            ->toContain('WG_EASY_PASSWORD="new-secret-password"');
    } finally {
        $app->useEnvironmentPath($originalEnvironmentPath);
        $app->loadEnvironmentFrom($originalEnvironmentFile);
        File::deleteDirectory($envRoot);
    }
});

it('does not leak backend password action values from wg-easy state failures', function (
    string $failingAction,
    string $failureMessage,
    string $remoteCode,
): void {
    $hash = '$argon2id$v=19$m=65536,t=3,p=4$hash$hash';
    $newPassword = 'new-secret-password';
    $transport = new WgEasyVpnBackendStateTransport(
        static function (Node $node, string $script, array $options) use ($failingAction, $hash, $newPassword, $remoteCode): RemoteShellResult {
            if (str_contains($script, $failingAction)) {
                return new RemoteShellResult(
                    exitCode: 1,
                    stdout: json_encode([
                        'error' => [
                            'code' => $remoteCode,
                            'message' => "remote leak {$newPassword} {$hash}",
                        ],
                        'meta' => [
                            'raw' => "{$newPassword} {$hash}",
                        ],
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    stderr: "stderr {$newPassword} {$hash}",
                    durationMs: 1,
                );
            }

            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(JsonEnvelope::success(['updated' => true]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                stderr: '',
                durationMs: 1,
            );
        },
    );

    try {
        wgEasyVpnBackendReadyForPasswordRotation($transport)
            ->changeWebUiPassword($newPassword);

        $this->fail('Expected backend wg-easy password action failure.');
    } catch (RuntimeException $exception) {
        $probe = wgEasyVpnBackendExceptionProbe($exception);
        $meta = wgEasyVpnBackendExceptionMeta($exception);
        $completed = wgEasyVpnBackendLocalExecutorCompletedProperties();
        $failed = array_values(array_filter(
            $completed,
            fn (array $properties): bool => ($properties['status'] ?? null) === 'failed',
        ));

        expect($exception->getMessage())->toBe($failureMessage)
            ->and($probe)->not->toContain($newPassword)
            ->and($probe)->not->toContain($hash)
            ->and($meta)->toHaveKey('wg_easy_state_error_code', $remoteCode)
            ->and($failed)->toHaveCount(1)
            ->and($failed[0]['stdout_summary'])->toBe('<suppressed>')
            ->and($failed[0]['stderr_summary'])->toBe('<suppressed>');
    }
})->with([
    'user password update' => ['update-user-password', 'Could not update VPN web UI password.', 'user_not_found'],
    'session password update' => ['update-session-password', 'Could not rotate VPN web UI sessions.', 'session_password_not_found'],
]);

it('does not leak password action values from transport exception messages or metadata', function (): void {
    $hash = 'SENTINEL-PASSWORD-HASH-EXCEPTION';
    $newPassword = 'SENTINEL-PASSWORD-ACTION-INPUT';
    $transport = new WgEasyVpnBackendStateTransport(
        static function (Node $node, string $script, array $options) use ($hash): RemoteShellResult {
            if (str_contains($script, "--action='update-user-password'")) {
                $metadata = ['script' => $script, 'password-hash' => $hash];

                throw new class("transport failed while running {$script}", $metadata) extends RuntimeException
                {
                    /**
                     * @param  array<string, mixed>  $meta
                     */
                    public function __construct(string $message, public readonly array $meta)
                    {
                        parent::__construct($message);
                    }
                };
            }

            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(JsonEnvelope::success(['updated' => true]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                stderr: '',
                durationMs: 1,
            );
        },
    );

    try {
        wgEasyVpnBackendReadyForPasswordRotation($transport, $hash)
            ->changeWebUiPassword($newPassword);

        $this->fail('Expected backend wg-easy password transport failure.');
    } catch (RuntimeException $exception) {
        $probe = wgEasyVpnBackendExceptionProbe($exception);
        $meta = wgEasyVpnBackendExceptionMeta($exception);
        $completed = wgEasyVpnBackendLocalExecutorCompletedProperties();
        $failed = array_values(array_filter(
            $completed,
            fn (array $properties): bool => ($properties['status'] ?? null) === 'failed',
        ));

        expect($exception->getMessage())->toBe('Remote local executor transport failed: <suppressed>')
            ->and($probe)->not->toContain($newPassword)
            ->and($probe)->not->toContain($hash)
            ->and($meta)->not->toBeEmpty()
            ->and(json_encode($meta, JSON_THROW_ON_ERROR))->not->toContain($hash)
            ->and($failed)->toHaveCount(1)
            ->and($failed[0]['exception_message'])->toBe('<suppressed>')
            ->and(json_encode($failed, JSON_THROW_ON_ERROR))->not->toContain($hash)
            ->and(json_encode($completed, JSON_THROW_ON_ERROR))->not->toContain($newPassword);
    }
});

it('raises a generic exception when backend wg-easy state output is not parseable JSON', function (): void {
    $secret = 'secret-token-probe-XYZ';
    $transport = new WgEasyVpnBackendStateTransport(new RemoteShellResult(
        exitCode: 1,
        stdout: "not-json {$secret}",
        stderr: "stderr {$secret}",
        durationMs: 1,
    ));

    try {
        wgEasyVpnBackendReadyForPasswordRotation($transport)
            ->changeWebUiPassword('new-secret-password');

        $this->fail('Expected backend wg-easy state parsing to fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Could not verify VPN web UI database writability.')
            ->and(wgEasyVpnBackendExceptionProbe($exception))->not->toContain($secret)
            ->and(wgEasyVpnBackendExceptionProbe($exception))->not->toContain('not-json');
    }
});

it('does not expose backend wg-easy state error messages from remote failure envelopes', function (): void {
    $secret = 'secret-token-probe-XYZ';
    $transport = new WgEasyVpnBackendStateTransport(new RemoteShellResult(
        exitCode: 1,
        stdout: json_encode([
            'error' => [
                'code' => 'database_missing',
                'message' => "remote leak {$secret}",
            ],
            'meta' => [
                'raw' => $secret,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        stderr: "stderr {$secret}",
        durationMs: 1,
    ));

    try {
        wgEasyVpnBackendReadyForPasswordRotation($transport)
            ->changeWebUiPassword('new-secret-password');

        $this->fail('Expected backend wg-easy state failure.');
    } catch (RuntimeException $exception) {
        $probe = wgEasyVpnBackendExceptionProbe($exception);

        expect($exception->getMessage())->toBe('Could not verify VPN web UI database writability.')
            ->and($probe)->not->toContain($secret)
            ->and($probe)->not->toContain('remote leak');
    }
});

it('only exposes whitelisted backend wg-easy state error codes in exception metadata', function (
    string $remoteCode,
    ?string $expectedCode,
): void {
    $transport = new WgEasyVpnBackendStateTransport(new RemoteShellResult(
        exitCode: 1,
        stdout: json_encode([
            'error' => [
                'code' => $remoteCode,
                'message' => 'remote state failure',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        stderr: '',
        durationMs: 1,
    ));

    try {
        wgEasyVpnBackendReadyForPasswordRotation($transport)
            ->changeWebUiPassword('new-secret-password');

        $this->fail('Expected backend wg-easy state failure.');
    } catch (RuntimeException $exception) {
        $meta = wgEasyVpnBackendExceptionMeta($exception);

        expect($exception->getMessage())->toBe('Could not verify VPN web UI database writability.')
            ->and($exception->getMessage())->not->toContain($remoteCode);

        if ($expectedCode === null) {
            expect($meta)->not->toHaveKey('wg_easy_state_error_code');
        } else {
            expect($meta)->toHaveKey('wg_easy_state_error_code', $expectedCode);
        }
    }
})->with([
    'whitelisted database_missing' => ['database_missing', 'database_missing'],
    'unknown code' => ['secret-token-probe-XYZ', null],
]);

function wgEasyVpnBackendReadyForPasswordRotation(
    WgEasyVpnBackendStateTransport $transport,
    string $hash = '$argon2id$v=19$m=65536,t=3,p=4$hash$hash',
): WgEasyVpnBackend {
    $node = Node::factory()->create([
        'name' => 'gateway-1',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'vpn',
        'status' => 'active',
    ]);

    Http::fake([
        'http://127.0.0.1:51821/api/session' => Http::response(['status' => 'success'], 200, [
            'Set-Cookie' => 'wg-easy=session-token; Path=/; HttpOnly',
        ]),
        'http://127.0.0.1:51821/api/client' => Http::response([], 200),
    ]);

    Process::fake(function ($process) use ($hash) {
        if (str_contains((string) $process->command, 'docker exec -i -w /app/server wg-easy node')) {
            return Process::result($hash);
        }

        return Process::result();
    });

    return new WgEasyVpnBackend(
        username: 'orbit',
        password: 'current-secret-password',
        localExecutor: wgEasyVpnBackendExecutor($transport),
        vpnNodeResolver: app(VpnNodeResolver::class),
    );
}

/**
 * @return array<string, mixed>
 */
function wgEasyVpnBackendExceptionMeta(Throwable $exception): array
{
    if (! property_exists($exception, 'meta')) {
        return [];
    }

    $meta = $exception->meta;

    return is_array($meta) ? $meta : [];
}

function wgEasyVpnBackendExceptionProbe(Throwable $exception): string
{
    return json_encode([
        'message' => $exception->getMessage(),
        'code' => $exception->getCode(),
        'metadata' => wgEasyVpnBackendExceptionMeta($exception),
        'trace' => $exception->getTraceAsString(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * @return list<array<string, mixed>>
 */
function wgEasyVpnBackendLocalExecutorDispatchingProperties(): array
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
function wgEasyVpnBackendLocalExecutorCompletedProperties(): array
{
    return DB::table('activity_log')
        ->where('log_name', 'local_executor')
        ->where('event', 'local_executor.completed')
        ->orderBy('id')
        ->get()
        ->map(fn (object $activity): array => json_decode((string) $activity->properties, true, flags: JSON_THROW_ON_ERROR))
        ->all();
}

function wgEasyVpnBackendExecutor(WgEasyVpnBackendStateTransport $transport): RemoteLocalExecutor
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

final class WgEasyVpnBackendStateTransport implements RemoteExecutor
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
