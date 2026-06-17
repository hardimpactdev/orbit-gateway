<?php

declare(strict_types=1);

use App\E2E\Support\DockerInstance;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ECurrentCheckout;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyCache;
use App\E2E\Support\E2ETopologyFactory;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\E2ETopologyUnavailable;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * Acquire a prepared E2E topology and wrap it in the small Pest-facing helper
 * API used by feature tests.
 *
 * @param  array<string, string>|null  $sshUsers
 */
function e2eTopology(E2ETopologyKind $kind, ?array $sshUsers = null, bool $withGatewayApi = false, bool $sourceMountedCheckout = false): E2ETopologyHarness
{
    $sshUsers ??= ['operator' => E2EConfig::fromEnvironment()->operatorUser];
    $withGatewayApi = $withGatewayApi || e2eGatewayApiByDefault();

    if (E2ETopologyCache::enabled()) {
        return E2ETopologyCache::acquire($kind, $sshUsers, $withGatewayApi, $sourceMountedCheckout);
    }

    $factory = E2ETopologyFactory::fromEnvironment()
        ->withSshUsers($sshUsers);

    if ($withGatewayApi) {
        $factory = $factory->withGatewayApi();
    }

    if ($sourceMountedCheckout) {
        $factory = $factory->withSourceMountedCheckout();
    }

    try {
        $lease = $factory->require($kind);
    } catch (E2ETopologyUnavailable $exception) {
        if (e2eFailsOnTopologyUnavailable()) {
            throw $exception;
        }

        test()->markTestSkipped($exception->getMessage());
    }

    return new E2ETopologyHarness($lease);
}

function e2eFailsOnTopologyUnavailable(): bool
{
    $value = getenv('ORBIT_E2E_FAIL_ON_TOPOLOGY_UNAVAILABLE');

    return is_string($value)
        && in_array(strtolower($value), ['1', 'true', 'yes'], true);
}

function e2eGatewayApiByDefault(): bool
{
    $value = getenv('ORBIT_E2E_GATEWAY_API');

    return is_string($value)
        && in_array(strtolower($value), ['1', 'true', 'yes'], true);
}

function e2eUsesDockerDnsAliasTopology(): bool
{
    return getenv('ORBIT_E2E_TOPOLOGY_PROVIDER') === 'docker';
}

function e2eRestartGatewayApi(E2ETopologyHarness $topology, string $label): void
{
    $gatewayApiIp = $topology->lease()->gatewayApiIp();

    if (e2eUsesDockerDnsAliasTopology()) {
        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            $label,
            $topology->checkout('gateway'),
            gatewayIp: '10.6.0.2',
            wireguardIdentity: '10.6.0.2',
            bindAddress: '0.0.0.0',
            certKey: 'gateway',
            certSans: ['10.6.0.2', 'gateway'],
            peerIdentityMap: e2eDockerDnsAliasPeerIdentityMap($topology),
        );
        e2eConfigureCurrentCheckoutGatewaySettingsIfAvailable($topology);

        return;
    }

    E2EGatewayApi::restart(
        $topology->instance('gateway'),
        $label,
        $topology->checkout('gateway'),
        gatewayIp: $gatewayApiIp,
    );
    e2eConfigureCurrentCheckoutGatewaySettingsIfAvailable($topology);
}

function e2eGatewayApiUrl(E2ETopologyHarness $topology): string
{
    if (e2eUsesDockerDnsAliasTopology()) {
        return 'https://gateway';
    }

    return 'https://'.$topology->lease()->gatewayApiIp();
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function e2eJsonCommandData(array $payload): array
{
    $successData = $payload['success']['data'] ?? null;

    if (is_array($successData)) {
        return $successData;
    }

    if (($payload['event'] ?? null) !== 'complete') {
        return [];
    }

    $frame = $payload['data'] ?? null;

    if (! is_array($frame)) {
        return [];
    }

    $data = $frame['data'] ?? null;

    return is_array($data) ? $data : [];
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function e2eJsonCommandResultData(array $payload): array
{
    $data = e2eJsonCommandData($payload);
    $result = $data['result'] ?? null;

    if (is_array($result) && (array_key_exists('workspace', $result) || array_key_exists('meta', $result))) {
        return $result;
    }

    return $data;
}

function e2eGatewayWireGuardIp(E2ETopologyHarness $topology): string
{
    if (e2eUsesDockerDnsAliasTopology()) {
        return '10.6.0.2';
    }

    return $topology->lease()->gatewayApiIp();
}

function e2eConfigureCurrentCheckoutGatewaySettingsIfAvailable(E2ETopologyHarness $topology): void
{
    foreach (['operator', 'gateway'] as $role) {
        if (array_key_exists($role, $topology->checkouts())) {
            e2eConfigureCurrentCheckoutGatewaySettings($topology, $role);
        }
    }
}

function e2eConfigureCurrentCheckoutGatewaySettings(E2ETopologyHarness $topology, string $role = 'operator'): void
{
    if (e2eRoleUsesDockerHostLauncher($topology, $role)) {
        e2eConfigureCurrentCheckoutCliGatewaySettings($topology, $role);
        e2eConfigureCurrentCheckoutRootGatewaySettings($topology, $role, null, e2eGatewayCaUrl($topology));

        return;
    }

    $caPemPath = e2eRoleUsesDockerTopologyNode($topology, $role)
        ? e2eInstallCurrentCheckoutGatewayCa($topology, $role)
        : null;

    e2eConfigureCurrentCheckoutRootGatewaySettings($topology, $role, $caPemPath);
}

function e2eConfigureCurrentCheckoutRootGatewaySettings(E2ETopologyHarness $topology, string $role, ?string $caPemPath, ?string $gatewayCaUrl = null): void
{
    $checkout = escapeshellarg($topology->checkout($role));
    $gatewayUrlValue = var_export(e2eGatewayApiUrl($topology), true);
    $gatewayIpValue = var_export(e2eGatewayWireGuardIp($topology), true);
    $caPemPathValue = var_export($caPemPath, true);
    $gatewayCaUrlValue = var_export($gatewayCaUrl, true);

    $php = <<<PHP
\$gatewayCaUrl = {$gatewayCaUrlValue};
\$caPemPath = {$caPemPathValue};
\$caSha256 = null;

if (\$gatewayCaUrl !== null) {
    \$rootCa = null;
    \$response = @file_get_contents(\$gatewayCaUrl, false, stream_context_create([
        'http' => ['timeout' => 5],
    ]));

    if (is_string(\$response) && \$response !== '') {
        \$decoded = json_decode(\$response, true);
        \$rootCa = is_array(\$decoded)
            ? (\$decoded['success']['data']['root_ca'] ?? \$decoded['data']['root_ca'] ?? null)
            : \$response;
    }

    if (is_string(\$rootCa)
        && str_contains(\$rootCa, '-----BEGIN CERTIFICATE-----')
        && str_contains(\$rootCa, '-----END CERTIFICATE-----')) {
        \$caPemPath = rtrim((string) config('orbit.paths.config_root'), '/').'/gateway-ca/orbit.crt';
        \\Illuminate\\Support\\Facades\\File::ensureDirectoryExists(dirname(\$caPemPath));
        \\Illuminate\\Support\\Facades\\File::put(\$caPemPath, \$rootCa);
        \$caSha256 = hash('sha256', \$rootCa);
    }
}

\$settings = \\App\\Models\\LocalGatewaySettings::current();
\$settings->fill([
    'gateway_url' => {$gatewayUrlValue},
    'gateway_wg_ip' => {$gatewayIpValue},
]);
if (\$caPemPath !== null) {
    \$settings->ca_pem_path = \$caPemPath;
}
if (\$caSha256 !== null) {
    \$settings->ca_sha256 = \$caSha256;
    \$settings->trusted_at = now();
}
\$settings->save();
echo 'configured';
PHP;

    $topology->ssh(
        $role,
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($php),
        timeoutSeconds: 120,
    );
}

function e2eConfigureCurrentCheckoutCliGatewaySettings(E2ETopologyHarness $topology, string $role): void
{
    $checkout = escapeshellarg($topology->checkout($role).'/apps/cli');
    $gatewayUrl = e2eGatewayCliUrl($topology);

    $command = implode(' && ', [
        "cd {$checkout}",
        'tmp="$(mktemp)"',
        '[ ! -f .env ] || grep -Ev \'^(ORBIT_GATEWAY_URL|ORBIT_GATEWAY_IDENTITY)=\' .env > "$tmp" || true',
        sprintf('printf \'ORBIT_GATEWAY_URL=%%s\\n\' %s >> "$tmp"', escapeshellarg($gatewayUrl)),
        'sudo install -m 0664 -o "$(id -un)" -g "$(id -gn)" "$tmp" .env',
        'rm -f "$tmp"',
    ]);

    $topology->ssh($role, $command, timeoutSeconds: 120);
}

function e2eInstallCurrentCheckoutGatewayCa(E2ETopologyHarness $topology, string $role): string
{
    $caPemPath = dirname($topology->checkout($role)).'/.config/orbit/ca/root.crt';
    $gatewayCaPath = dirname($topology->checkout('gateway')).'/.config/orbit/ca/root.crt';
    $rootCert = $topology->ssh(
        'gateway',
        'cat '.escapeshellarg($gatewayCaPath),
        timeoutSeconds: 120,
    )->output();

    $topology->ssh(
        $role,
        sprintf(
            'mkdir -p %s && printf %%s %s > %s',
            escapeshellarg(dirname($caPemPath)),
            escapeshellarg($rootCert),
            escapeshellarg($caPemPath),
        ),
        timeoutSeconds: 120,
    );

    return $caPemPath;
}

function e2eGatewayCliUrl(E2ETopologyHarness $topology): string
{
    if (e2eUsesDockerDnsAliasTopology()) {
        return 'http://gateway';
    }

    return 'http://'.$topology->lease()->gatewayApiIp();
}

function e2eGatewayCaUrl(E2ETopologyHarness $topology): string
{
    return e2eGatewayCliUrl($topology).'/api/ca/root';
}

/**
 * @return array<string, string>
 */
function e2eDockerDnsAliasPeerIdentityMap(E2ETopologyHarness $topology): array
{
    $canonical = [
        'gateway' => '10.6.0.2',
        'operator' => '10.6.0.3',
        'dev' => '10.6.0.4',
        'prod' => '10.6.0.5',
        'ingress' => '10.6.0.7',
    ];

    $lease = $topology->lease();
    $instances = [
        'gateway' => $lease->gateway(),
        'operator' => $lease->operator(),
        'dev' => $lease->devApp(),
        'prod' => $lease->prodApp(),
        'ingress' => $lease->ingress(),
    ];

    $map = [
        '127.0.0.1' => $canonical['gateway'],
        '::1' => $canonical['gateway'],
    ];
    $mappedInstances = [];

    foreach ($instances as $role => $instance) {
        if ($instance !== null) {
            if (in_array($instance->name(), $mappedInstances, true)) {
                continue;
            }

            $mappedInstances[] = $instance->name();
            $map[$instance->waitForIpv4()] = $canonical[$role];
        }
    }

    return $map;
}

/**
 * Install the current checkout into selected topology roles and return their
 * remote checkout paths.
 *
 * @param  list<string>|null  $roles
 * @param  array<string, string>  $users
 * @return array<string, string>
 */
function e2eCheckout(E2ETopologyLease|E2ETopologyHarness $topology, ?array $roles = null, array $users = []): array
{
    if ($topology instanceof E2ETopologyHarness) {
        return $topology->withCurrentCheckout($roles, $users)->checkouts();
    }

    return E2ECurrentCheckout::installOnTopology($topology, $roles, $users);
}

function e2eRoleUsesDockerTopologyNode(E2ETopologyHarness $topology, string $role): bool
{
    return $topology->instance($role) instanceof DockerInstance;
}

function e2eRoleUsesDockerRuntime(E2ETopologyHarness $topology, string $role): bool
{
    return $role === 'gateway' && e2eRoleUsesDockerTopologyNode($topology, $role);
}

function e2eRoleUsesDockerHostLauncher(E2ETopologyHarness $topology, string $role): bool
{
    return e2eRoleUsesDockerTopologyNode($topology, $role)
        && in_array($role, ['operator', 'gateway', 'dev', 'prod', 'agent', 'ingress'], true);
}

function e2eRuntimeContainerName(E2ETopologyHarness $topology, string $role): string
{
    return $topology->instance($role)->name().'-orbit-gateway';
}

function e2eDockerRuntimeExecCommand(string $runtimeContainer, string $command): string
{
    return sprintf(
        'sudo docker exec %s sh -lc %s',
        escapeshellarg($runtimeContainer),
        escapeshellarg($command),
    );
}

function e2eRunInRoleRuntime(E2ETopologyHarness $topology, string $role, string $command, ?int $timeoutSeconds = null, bool $allowFailure = false): ProcessResult
{
    if (! e2eRoleUsesDockerRuntime($topology, $role)) {
        return $topology->ssh($role, $command, timeoutSeconds: $timeoutSeconds, allowFailure: $allowFailure);
    }

    $result = $topology->instance($role)->exec(
        e2eDockerRuntimeExecCommand(e2eRuntimeContainerName($topology, $role), $command),
        timeoutSeconds: $timeoutSeconds,
    );

    if (! $allowFailure && ! $result->successful()) {
        throw new RuntimeException(trim("Docker runtime command failed: {$command}\n".$result->output().$result->errorOutput()));
    }

    return $result;
}

function e2ePutRuntimeFile(E2ETopologyHarness $topology, string $role, string $path, string $contents, ?int $timeoutSeconds = null): void
{
    e2eRunInRoleRuntime(
        $topology,
        $role,
        sprintf(
            'cat > %s <<%s%s%s',
            escapeshellarg($path),
            escapeshellarg('ORBIT_E2E_FILE'),
            "\n{$contents}\n",
            'ORBIT_E2E_FILE',
        ),
        timeoutSeconds: $timeoutSeconds,
    );
}

function e2eOrbitWrapperScript(string $checkout, bool $dockerRuntime, ?string $executorNodeIdentity = null, bool $hostLauncher = false): string
{
    return E2ECurrentCheckout::orbitWrapperScript($checkout, $dockerRuntime, $executorNodeIdentity, $hostLauncher);
}

function e2ePhpServerCommand(int $port, string $routerPath, string $logPath, string $pidPath): string
{
    $server = 'p'.'hp -'.'S';
    $runner = 'no'.'hup '.$server;

    return sprintf(
        'rm -f %s %s; %s 127.0.0.1:%d %s > %s 2>&1 & echo $! > %s',
        escapeshellarg($pidPath),
        escapeshellarg($logPath),
        $runner,
        $port,
        escapeshellarg($routerPath),
        escapeshellarg($logPath),
        escapeshellarg($pidPath),
    );
}

function e2eStartRuntimePhpServer(E2ETopologyHarness $topology, string $role, int $port, string $routerPath, string $logPath, string $pidPath): void
{
    e2eRunInRoleRuntime(
        $topology,
        $role,
        e2ePhpServerCommand($port, $routerPath, $logPath, $pidPath),
        timeoutSeconds: 60,
    );
}

function e2eWaitForRuntimeHttpEndpoint(E2ETopologyHarness $topology, string $role, int $port, string $path, string $logPath): void
{
    e2eRunInRoleRuntime(
        $topology,
        $role,
        sprintf(
            'for i in $(seq 1 30); do curl -fsS -X POST %s -d "{}" >/dev/null 2>&1 && exit 0; sleep 1; done; cat %s; exit 1',
            escapeshellarg("http://127.0.0.1:{$port}{$path}"),
            escapeshellarg($logPath),
        ),
        timeoutSeconds: 45,
    );
}

function e2eStopRuntimePhpServer(E2ETopologyHarness $topology, string $role, string $pidPath): void
{
    e2eRunInRoleRuntime(
        $topology,
        $role,
        sprintf('test ! -f %s || kill "$(cat %s)" >/dev/null 2>&1 || true', escapeshellarg($pidPath), escapeshellarg($pidPath)),
        timeoutSeconds: 30,
        allowFailure: true,
    );
}

function e2eGrantNodeAccess(E2ETopologyHarness $topology, string $consumer = 'operator-1', string $serving = 'app-dev-1'): void
{
    $consumerValue = var_export($consumer, true);
    $servingValue = var_export($serving, true);
    $checkout = escapeshellarg($topology->checkout('gateway'));

    $script = <<<PHP
\$nodes = \\App\\Models\\Node::query()
    ->whereIn('name', [{$consumerValue}, {$servingValue}])
    ->pluck('id', 'name');

foreach ([{$consumerValue}, {$servingValue}] as \$name) {
    if (! \$nodes->has(\$name)) {
        throw new \\RuntimeException("Missing prepared node [{\$name}].");
    }
}

\$servingName = {$servingValue};
\$servingRole = str_contains(\$servingName, 'app-prod') ? 'app-prod' : (str_contains(\$servingName, 'app-dev') ? 'app-dev' : null);

if (\$servingRole !== null) {
    \\App\\Models\\NodeRoleAssignment::query()->updateOrCreate(
        [
            'node_id' => \$nodes->get({$servingValue}),
            'role' => \$servingRole,
        ],
        [
            'status' => 'active',
            'settings' => [],
            'last_error' => null,
            'converged_at' => now(),
        ],
    );
}

\\Illuminate\\Support\\Facades\\DB::table('node_access')->updateOrInsert([
    'consumer_node_id' => \$nodes->get({$consumerValue}),
    'serving_node_id' => \$nodes->get({$servingValue}),
], [
    'permissions' => json_encode(['*']),
    'custom_permissions' => json_encode([]),
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'granted';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

function e2eTopologyCleanup(bool $passed, E2ETopologyLease|E2ETopologyHarness $topology): void
{
    if ($passed || ! e2eProvisionKeepsFailures()) {
        $topology->cleanup();

        return;
    }

    e2eProvisionReportDangling($topology->instanceNames());
}

function e2eProvisionKeepsFailures(): bool
{
    $value = getenv('ORBIT_E2E_KEEP_ON_FAILURE');

    return ! is_string($value) || ! in_array(strtolower($value), ['0', 'false', 'no'], true);
}

/**
 * @param  list<string>  $instanceNames
 */
function e2eProvisionReportDangling(array $instanceNames): void
{
    $instanceNames = array_values(array_unique(array_filter($instanceNames)));

    if ($instanceNames === []) {
        fwrite(STDERR, "E2E provision test failed; no tracked instances were available to report.\n");

        return;
    }

    fwrite(STDERR, "E2E provision test failed; keeping instances for inspection:\n");

    foreach ($instanceNames as $instanceName) {
        fwrite(STDERR, "  - {$instanceName}\n");
    }

    fwrite(STDERR, "Reap later with: composer e2e:reap-incus -- --force --older-than=0m\n");
    fwrite(STDERR, "Set ORBIT_E2E_KEEP_ON_FAILURE=0 to restore cleanup-on-failure behavior.\n");
}
