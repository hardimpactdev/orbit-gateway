<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class WgEasyServiceInstaller
{
    public const string Image = 'ghcr.io/wg-easy/wg-easy:15';

    private const string WG_EASY_STATE_COMMAND = 'internal:wg-easy:state';

    private const string ACTION_UPDATE_USER = 'update-user';

    private const string ACTION_UPDATE_GENERAL = 'update-general';

    private const string ACTION_ENSURE_WRITABLE = 'ensure-writable';

    private const string ACTION_UPSERT_PEER = 'upsert-peer';

    private const string ACTION_DELETE_PEER = 'delete-peer';

    private const string ACTION_UPDATE_INTERFACE = 'update-interface';

    private const array SAFE_WG_EASY_STATE_ERROR_CODES = [
        'database_missing',
        'database_unwritable',
        'home_directory_unavailable',
        'invalid_action',
        'invalid_token',
        'interface_not_found',
        'missing_token',
        'peer_not_found',
        'query_failed',
        'session_password_not_found',
        'user_not_found',
        'validation_failed',
    ];

    public function __construct(
        private readonly string $rootPath,
        private readonly ?string $statePath = null,
        private readonly ?RemoteLocalExecutor $localExecutor = null,
        private readonly ?VpnNodeResolver $vpnNodeResolver = null,
    ) {}

    public function install(
        string $publicHost,
        string $username,
        string $password,
        string $wireguardCidr = '10.6.0.0/24',
        int $wireguardPort = 51820,
        string $dnsIp = '10.6.0.1',
    ): void {
        if ($publicHost === '') {
            throw new RuntimeException('INIT_HOST is required to install wg-easy.');
        }

        if ($username === '') {
            throw new RuntimeException('A wg-easy admin username is required.');
        }

        if ($password === '') {
            throw new RuntimeException('A wg-easy admin password is required.');
        }

        $directory = $this->rootPath.'/wg-easy';
        File::ensureDirectoryExists($directory);
        File::ensureDirectoryExists($this->statePath());
        $composePath = $directory.'/docker-compose.yaml';

        $compose = $this->renderCompose($publicHost, $username, $password, $wireguardCidr, $wireguardPort, $dnsIp);
        $existing = File::exists($composePath) ? File::get($composePath) : null;

        if ($existing !== $compose) {
            File::put($composePath, $compose);
        }

        $result = Process::timeout(180)->run(sprintf(
            "%s\n\$ORBIT_DOCKER compose -f %s up -d",
            $this->dockerShellPrefix(),
            escapeshellarg($composePath),
        ));

        if (! $result->successful()) {
            throw new RuntimeException(
                'Failed to start wg-easy: '.trim($result->errorOutput().' '.$result->output())
            );
        }

        $this->waitUntilReady();
        $this->ensureWgEasyStateWritable();
        $this->convergeServerAddress($publicHost, $wireguardCidr, $dnsIp);
    }

    public function publicKey(): string
    {
        $this->waitUntilReady();

        $result = Process::timeout(30)->run(sprintf(
            "%s\n\$ORBIT_DOCKER exec wg-easy wg show wg0 public-key",
            $this->dockerShellPrefix(),
        ));

        if (! $result->successful()) {
            throw new RuntimeException(
                'Failed to read wg-easy WireGuard public key: '.trim($result->errorOutput().' '.$result->output())
            );
        }

        $publicKey = trim($result->output());

        if ($publicKey === '') {
            throw new RuntimeException('wg-easy WireGuard public key is empty.');
        }

        return $publicKey;
    }

    /**
     * @param  list<array{name: string, private_key: string, public_key: string, address: string, pre_shared_key: string}>  $peers
     */
    public function configurePeers(array $peers): void
    {
        if ($peers === []) {
            return;
        }

        $this->waitUntilReady();

        $runtimeCommands = [];

        foreach ($peers as $peer) {
            $this->deleteWgEasyPeer($peer['name']);
            $this->upsertWgEasyPeer($peer);

            $runtimeCommands[] = sprintf(
                '$ORBIT_DOCKER exec wg-easy sh -lc %s',
                escapeshellarg(sprintf(
                    'tmp="$(mktemp)" && printf %s %s > "$tmp" && wg set wg0 peer %s preshared-key "$tmp" allowed-ips %s; status="$?"; rm -f "$tmp"; exit "$status"',
                    escapeshellarg('%s\n'),
                    escapeshellarg($peer['pre_shared_key']),
                    escapeshellarg($peer['public_key']),
                    escapeshellarg($peer['address'].'/32'),
                )),
            );
        }

        $script = sprintf(
            <<<'SH'
set -eu
%s
%s
SH,
            $this->dockerShellPrefix(),
            implode("\n", $runtimeCommands),
        );

        $result = Process::timeout(120)->run($script);

        if (! $result->successful()) {
            throw new RuntimeException(
                'Failed to configure wg-easy peers: '.trim($result->errorOutput().' '.$result->output())
            );
        }
    }

    private function waitUntilReady(): void
    {
        $result = Process::timeout(75)->run(sprintf(
            <<<'SH'
%s
for i in $(seq 1 60); do
    $ORBIT_DOCKER exec wg-easy test -f /etc/wireguard/wg-easy.db && $ORBIT_DOCKER exec wg-easy ip link show wg0 >/dev/null 2>&1 && exit 0
    sleep 1
done
exit 1
SH,
            $this->dockerShellPrefix(),
        ));

        if ($result->successful()) {
            if (File::exists($this->statePath().'/wg-easy.db')) {
                $this->ensureStateWritable();
            }

            return;
        }

        throw new RuntimeException(
            'wg-easy did not become ready: '.trim($result->errorOutput().' '.$result->output())
        );
    }

    private function convergeServerAddress(string $publicHost, string $wireguardCidr, string $dnsIp): void
    {
        $prefix = $this->cidrPrefix($wireguardCidr);
        $serverAddress = "{$dnsIp}/{$prefix}";

        $result = Process::timeout(30)->run(sprintf(
            <<<'SH'
%s
$ORBIT_DOCKER exec wg-easy ip addr replace %s dev wg0
$ORBIT_DOCKER exec wg-easy ip route replace %s dev wg0
SH,
            $this->dockerShellPrefix(),
            escapeshellarg($serverAddress),
            escapeshellarg($wireguardCidr),
        ));

        if (! $result->successful()) {
            throw new RuntimeException(
                'Failed to converge wg-easy server address: '.trim($result->errorOutput().' '.$result->output())
            );
        }

        $this->updateWgEasyInterface($wireguardCidr);
        $this->updateWgEasyUserConfig($publicHost, '["'.$dnsIp.'"]', 25);
        $this->updateWgEasyGeneralSetupStep(0);
    }

    private function ensureStateWritable(): void
    {
        $result = Process::timeout(30)->run(sprintf(
            <<<'SH'
set -e
if command -v sudo >/dev/null 2>&1; then
    sudo chown -R "$(id -u):$(id -g)" %s
else
    chown -R "$(id -u):$(id -g)" %s
fi
SH,
            $this->statePathForShell(),
            $this->statePathForShell(),
        ));

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException(
            'Failed to make wg-easy state writable: '.trim($result->errorOutput().' '.$result->output())
        );
    }

    private function renderCompose(
        string $publicHost,
        string $username,
        string $password,
        string $wireguardCidr,
        int $wireguardPort,
        string $dnsIp,
    ): string {
        $image = self::Image;

        return <<<YAML
services:
  wg-easy:
    image: {$image}
    container_name: wg-easy
    restart: unless-stopped
    environment:
{$this->composeEnvironmentLine('INIT_ENABLED', 'true')}
{$this->composeEnvironmentLine('INIT_USERNAME', $username)}
{$this->composeEnvironmentLine('INIT_PASSWORD', $password)}
{$this->composeEnvironmentLine('INIT_HOST', $publicHost)}
{$this->composeEnvironmentLine('INIT_PORT', (string) $wireguardPort)}
{$this->composeEnvironmentLine('INIT_DNS', $dnsIp)}
{$this->composeEnvironmentLine('INIT_ALLOWED_IPS', $wireguardCidr)}
{$this->composeEnvironmentLine('INSECURE', 'true')}
{$this->composeEnvironmentLine('PORT', '51821')}
{$this->composeEnvironmentLine('HOST', '0.0.0.0')}
{$this->composeEnvironmentLine('DISABLE_IPV6', 'true')}
    ports:
      - "{$wireguardPort}:{$wireguardPort}/udp"
      - "127.0.0.1:51821:51821/tcp"
    cap_add:
      - NET_ADMIN
      - SYS_MODULE
    sysctls:
      - net.ipv4.conf.all.src_valid_mark=1
      - net.ipv4.ip_forward=1
    volumes:
      - {$this->statePath()}:/etc/wireguard
      - /lib/modules:/lib/modules:ro

YAML;
    }

    protected function cidrPrefix(string $wireguardCidr): int
    {
        [, $prefix] = explode('/', $wireguardCidr, 2);

        return (int) $prefix;
    }

    private function composeEnvironmentLine(string $key, string $value): string
    {
        return "      - '".$key.'='.str_replace("'", "''", $value)."'\n";
    }

    protected function statePath(): string
    {
        if ($this->statePath !== null) {
            return $this->statePath;
        }

        return '/home/orbit/.wg-easy';
    }

    private function statePathForShell(): string
    {
        return escapeshellarg($this->statePath());
    }

    protected function dockerShellPrefix(): string
    {
        return 'if docker ps >/dev/null 2>&1; then ORBIT_DOCKER=docker; else ORBIT_DOCKER="sudo docker"; fi';
    }

    protected function ipv6For(string $ipv4): string
    {
        $lastOctet = (int) substr(strrchr($ipv4, '.') ?: '.0', 1);

        return 'fdcc:ad94:bacf:61a4::cafe:'.dechex($lastOctet);
    }

    protected function deleteWgEasyPeer(string $name): void
    {
        $this->runWgEasyStateAction(
            action: self::ACTION_DELETE_PEER,
            commandOptions: [
                'name' => $name,
            ],
            failureMessage: 'Failed to delete wg-easy peer.',
            successfulErrorCodes: ['peer_not_found'],
        );
    }

    /**
     * @param  array{name: string, private_key: string, public_key: string, address: string, pre_shared_key: string}  $peer
     */
    protected function upsertWgEasyPeer(array $peer): void
    {
        $this->runWgEasyStateAction(
            action: self::ACTION_UPSERT_PEER,
            commandOptions: [
                'name' => $peer['name'],
                'ipv4' => $peer['address'],
                'ipv6' => $this->ipv6For($peer['address']),
                'private-key' => $peer['private_key'],
                'public-key' => $peer['public_key'],
                'pre-shared-key' => $peer['pre_shared_key'],
            ],
            failureMessage: 'Failed to configure wg-easy peer.',
            transportOptions: [
                'redact_command_options' => ['private-key', 'pre-shared-key'],
            ],
        );
    }

    protected function updateWgEasyInterface(string $wireguardCidr): void
    {
        $this->runWgEasyStateAction(
            action: self::ACTION_UPDATE_INTERFACE,
            commandOptions: [
                'ipv4-cidr' => $wireguardCidr,
            ],
            failureMessage: 'Failed to update wg-easy interface configuration.',
        );
    }

    protected function updateWgEasyUserConfig(string $host, string $defaultDns, int $defaultPersistentKeepalive): void
    {
        $this->runWgEasyStateAction(
            action: self::ACTION_UPDATE_USER,
            commandOptions: [
                'host' => $host,
                'default-dns' => $defaultDns,
                'default-persistent-keepalive' => $defaultPersistentKeepalive,
            ],
            failureMessage: 'Failed to update wg-easy user configuration.',
        );
    }

    protected function updateWgEasyGeneralSetupStep(int $setupStep): void
    {
        $this->runWgEasyStateAction(
            action: self::ACTION_UPDATE_GENERAL,
            commandOptions: [
                'setup-step' => $setupStep,
            ],
            failureMessage: 'Failed to update wg-easy general configuration.',
        );
    }

    protected function ensureWgEasyStateWritable(): void
    {
        $this->runWgEasyStateAction(
            action: self::ACTION_ENSURE_WRITABLE,
            commandOptions: [],
            failureMessage: 'Failed to verify wg-easy state writability.',
        );
    }

    /**
     * @param  array<string, bool|float|int|string>  $commandOptions
     * @param  array{
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     * @param  list<string>  $successfulErrorCodes
     */
    private function runWgEasyStateAction(
        string $action,
        array $commandOptions,
        string $failureMessage,
        array $transportOptions = [],
        array $successfulErrorCodes = [],
    ): void {
        if (! $this->hasOperationTokenSigningKey()) {
            return;
        }

        $result = $this->localExecutor()->runInternal(
            node: $this->vpnNode(),
            commandName: self::WG_EASY_STATE_COMMAND,
            arguments: [],
            commandOptions: [
                'action' => $action,
                ...$commandOptions,
            ],
            transportOptions: [
                'timeout' => 30,
                'metadata' => [
                    'ORBIT_WG_EASY_DB_PATH' => $this->statePath().'/wg-easy.db',
                    'ORBIT_OPERATION_ID' => (string) Str::uuid(),
                ],
                ...$transportOptions,
            ],
        );

        $this->assertWgEasyStateSucceeded($result, $failureMessage, $successfulErrorCodes);
    }

    /**
     * @param  list<string>  $successfulErrorCodes
     */
    private function assertWgEasyStateSucceeded(
        RemoteShellResult $result,
        string $failureMessage,
        array $successfulErrorCodes = [],
    ): void {
        $envelope = $this->wgEasyStateEnvelope($result, $failureMessage);

        if ($result->successful() && is_array($envelope['success'] ?? null)) {
            return;
        }

        $code = $this->wgEasyStateFailureCode($envelope);

        if ($code !== null && in_array($code, $successfulErrorCodes, true)) {
            return;
        }

        throw $this->wgEasyStateFailure($failureMessage, $code);
    }

    /**
     * @return array<string, mixed>
     */
    private function wgEasyStateEnvelope(RemoteShellResult $result, string $failureMessage): array
    {
        try {
            $decoded = json_decode(trim($result->stdout), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WgEasyStateInstallerFailed($failureMessage);
        }

        if (! is_array($decoded) || ! (array_key_exists('success', $decoded) || array_key_exists('error', $decoded))) {
            throw new WgEasyStateInstallerFailed($failureMessage);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function wgEasyStateFailureCode(array $envelope): ?string
    {
        $error = is_array($envelope['error'] ?? null) ? $envelope['error'] : [];

        return $this->safeWgEasyStateErrorCode($error['code'] ?? null);
    }

    private function wgEasyStateFailure(string $failureMessage, ?string $code): WgEasyStateInstallerFailed
    {
        if ($code === null) {
            return new WgEasyStateInstallerFailed($failureMessage);
        }

        return new WgEasyStateInstallerFailed($failureMessage, [
            'wg_easy_state_error_code' => $code,
        ]);
    }

    private function safeWgEasyStateErrorCode(mixed $value): ?string
    {
        $code = $this->stringValue($value);

        if ($code === null) {
            return null;
        }

        return in_array($code, self::SAFE_WG_EASY_STATE_ERROR_CODES, true) ? $code : null;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function hasOperationTokenSigningKey(): bool
    {
        $secret = config('app.key');

        return is_string($secret) && trim($secret) !== '';
    }

    private function localExecutor(): RemoteLocalExecutor
    {
        return $this->localExecutor ?? app(RemoteLocalExecutor::class);
    }

    private function vpnNode(): Node
    {
        return $this->vpnNodeResolver()->activeVpnNode();
    }

    private function vpnNodeResolver(): VpnNodeResolver
    {
        return $this->vpnNodeResolver ?? app(VpnNodeResolver::class);
    }
}

final class WgEasyStateInstallerFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(string $message, public readonly array $meta = [])
    {
        parent::__construct($message);
    }
}
