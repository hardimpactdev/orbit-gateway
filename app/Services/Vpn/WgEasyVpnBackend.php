<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use App\Data\RemoteShell\RemoteShellResult;
use App\Data\Vpn\VpnBackendClient;
use App\Data\Vpn\VpnPasswordRotationResult;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final class WgEasyVpnBackend implements VpnBackend
{
    private const string WG_EASY_STATE_COMMAND = 'internal:wg-easy:state';

    private const string ACTION_ENSURE_WRITABLE = 'ensure-writable';

    private const string ACTION_UPDATE_USER_PASSWORD = 'update-user-password';

    private const string ACTION_UPDATE_SESSION_PASSWORD = 'update-session-password';

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

    private string $baseUrl = 'http://127.0.0.1:51821';

    private ?string $sessionCookie = null;

    public function __construct(
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly ?RemoteLocalExecutor $localExecutor = null,
        private readonly ?VpnNodeResolver $vpnNodeResolver = null,
    ) {}

    public static function fromConfig(): self
    {
        if (app()->bound(self::class)) {
            return app(self::class);
        }

        return new self(
            username: (string) config('services.wg_easy.username', config('orbit.wg_easy.username', 'orbit')),
            password: (string) config('services.wg_easy.password', config('orbit.wg_easy.password', '')),
        );
    }

    public function clients(?string $totp = null): array
    {
        $response = Http::withHeaders(['Cookie' => $this->authenticate($totp)])
            ->timeout(10)
            ->get("{$this->baseUrl}/api/client");

        if (! $response->successful()) {
            throw new RuntimeException('VPN backend unavailable.');
        }

        return array_map(static fn (mixed $client): VpnBackendClient => new VpnBackendClient(
            id: (string) ($client['id'] ?? ''),
            name: (string) ($client['name'] ?? ''),
            address: (string) ($client['ipv4Address'] ?? $client['address'] ?? ''),
            enabled: (bool) ($client['enabled'] ?? true),
            latestHandshakeAt: is_string($client['latestHandshakeAt'] ?? null) ? $client['latestHandshakeAt'] : null,
        ), array_values((array) $response->json()));
    }

    public function createClient(string $name, bool $includeConfig = false, ?string $totp = null): VpnBackendClient
    {
        if ($this->findClient($name, $totp) instanceof VpnBackendClient) {
            throw new RuntimeException('VPN client name is already in use.');
        }

        $response = Http::withHeaders(['Cookie' => $this->authenticate($totp)])
            ->timeout(10)
            ->post("{$this->baseUrl}/api/client", [
                'name' => $name,
                'expiresAt' => null,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('VPN backend unavailable.');
        }
        $client = array_find($this->clients($totp), fn ($candidate) => $candidate->name === $name);

        if ($client === null) {
            throw new RuntimeException('VPN client creation could not be verified.');
        }

        return new VpnBackendClient(
            id: $client->id,
            name: $client->name,
            address: $client->address,
            enabled: $client->enabled,
            latestHandshakeAt: $client->latestHandshakeAt,
            config: $includeConfig ? $this->clientConfig($client->id, $client->address, $totp) : null,
        );
    }

    public function enableClient(string $name, ?string $totp = null): VpnBackendClient
    {
        return $this->toggleClient($name, 'enable', true, $totp);
    }

    public function disableClient(string $name, ?string $totp = null): VpnBackendClient
    {
        return $this->toggleClient($name, 'disable', false, $totp);
    }

    public function removeClient(string $name, ?string $totp = null): void
    {
        $client = $this->findClient($name, $totp) ?? throw new RuntimeException('VPN client does not exist.');

        $response = Http::withHeaders(['Cookie' => $this->authenticate($totp)])
            ->timeout(10)
            ->delete("{$this->baseUrl}/api/client/{$client->id}");

        if (! $response->successful()) {
            throw new RuntimeException('VPN backend unavailable.');
        }
    }

    public function changeWebUiPassword(string $password, ?string $totp = null): VpnPasswordRotationResult
    {
        $this->clients($totp);
        $hash = $this->argon2Hash($password);

        $this->ensureWgEasyStateWritable();
        $this->updatePasswordHash($hash);
        $this->rotateSessionSecret();
        $this->updateEnvironmentPassword($password);

        return new VpnPasswordRotationResult(passwordChanged: true, sessionsInvalidated: true);
    }

    private function toggleClient(string $name, string $endpoint, bool $enabled, ?string $totp): VpnBackendClient
    {
        $client = $this->findClient($name, $totp) ?? throw new RuntimeException('VPN client does not exist.');

        if ($client->enabled === $enabled) {
            return $client;
        }

        $response = Http::withHeaders(['Cookie' => $this->authenticate($totp)])
            ->timeout(10)
            ->post("{$this->baseUrl}/api/client/{$client->id}/{$endpoint}");

        if (! $response->successful()) {
            throw new RuntimeException('VPN backend unavailable.');
        }

        return $this->findClient($name, $totp);
    }

    private function findClient(string $name, ?string $totp): ?VpnBackendClient
    {
        foreach ($this->clients($totp) as $client) {
            if ($client->name === $name) {
                return $client;
            }
        }

        return null;
    }

    private function clientConfig(string $clientId, string $clientAddress, ?string $totp): string
    {
        $response = Http::withHeaders(['Cookie' => $this->authenticate($totp)])
            ->timeout(10)
            ->get("{$this->baseUrl}/api/client/{$clientId}/configuration");

        if (! $response->successful()) {
            throw new RuntimeException('VPN client config could not be generated.');
        }

        return $this->withWireGuardServerDns($response->body(), $clientAddress);
    }

    private function withWireGuardServerDns(string $config, string $clientAddress): string
    {
        $serverAddress = $this->wireGuardServerAddress($clientAddress);

        if ($serverAddress === null) {
            return $config;
        }

        $lines = preg_split('/\r\n|\n|\r/', $config);

        if ($lines === false) {
            return $config;
        }

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*DNS\s*=/i', $line) !== 1) {
                continue;
            }

            $lines[$index] = "DNS = {$serverAddress}";

            return implode("\n", $lines);
        }

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*Address\s*=/i', $line) !== 1) {
                continue;
            }

            array_splice($lines, $index + 1, 0, "DNS = {$serverAddress}");

            return implode("\n", $lines);
        }

        return $config;
    }

    private function wireGuardServerAddress(string $clientAddress): ?string
    {
        $address = trim(explode('/', $clientAddress, 2)[0]);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $parts = explode('.', $address);
        $parts[3] = '1';

        return implode('.', $parts);
    }

    private function authenticate(?string $totp = null): string
    {
        if ($this->sessionCookie !== null) {
            return $this->sessionCookie;
        }

        if ($this->password === '') {
            throw new RuntimeException('VPN backend credentials are not configured.');
        }

        $payload = [
            'username' => $this->username,
            'password' => $this->password,
            'remember' => true,
        ];

        if ($totp !== null && $totp !== '') {
            $payload['totpCode'] = $totp;
        }

        $response = Http::asJson()
            ->timeout(10)
            ->post("{$this->baseUrl}/api/session", $payload);

        if (! $response->successful()) {
            throw new RuntimeException('VPN backend authentication failed.');
        }

        $cookie = (string) $response->header('Set-Cookie');

        if (preg_match('/wg-easy=([^;]+)/', $cookie, $matches) !== 1) {
            throw new RuntimeException('VPN backend authentication failed.');
        }

        return $this->sessionCookie = "wg-easy={$matches[1]}";
    }

    private function argon2Hash(string $password): string
    {
        $script = <<<'JS'
const chunks = [];
process.stdin.on('data', chunk => chunks.push(chunk));
process.stdin.on('end', async () => {
  try {
    const argon2 = require('argon2');
    console.log(await argon2.hash(Buffer.concat(chunks).toString()));
  } catch (error) {
    console.error(error.message);
    process.exit(1);
  }
});
JS;

        $result = Process::timeout(15)
            ->input($password)
            ->run('docker exec -i -w /app/server wg-easy node -e '.escapeshellarg($script));

        if (! $result->successful()) {
            throw new RuntimeException('Could not hash VPN web UI password.');
        }

        $hash = trim($result->output());

        if ($hash === '') {
            throw new RuntimeException('Could not hash VPN web UI password.');
        }

        return $hash;
    }

    private function updatePasswordHash(string $hash): void
    {
        $this->runWgEasyStateAction(
            action: self::ACTION_UPDATE_USER_PASSWORD,
            commandOptions: [
                'password-hash' => $hash,
            ],
            failureMessage: 'Could not update VPN web UI password.',
            transportOptions: [
                'redact_stdout' => true,
                'redact_stderr' => true,
                'redact_command_options' => ['password-hash'],
            ],
        );
    }

    private function rotateSessionSecret(): void
    {
        $hash = $this->argon2Hash(Str::random(128));

        $this->runWgEasyStateAction(
            action: self::ACTION_UPDATE_SESSION_PASSWORD,
            commandOptions: [
                'password-hash' => $hash,
            ],
            failureMessage: 'Could not rotate VPN web UI sessions.',
            transportOptions: [
                'redact_stdout' => true,
                'redact_stderr' => true,
                'redact_command_options' => ['password-hash'],
            ],
        );
    }

    private function ensureWgEasyStateWritable(): void
    {
        $this->runWgEasyStateAction(
            action: self::ACTION_ENSURE_WRITABLE,
            commandOptions: [],
            failureMessage: 'Could not verify VPN web UI database writability.',
        );
    }

    /**
     * @param  array<string, bool|float|int|string>  $commandOptions
     * @param  array{
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $transportOptions
     */
    private function runWgEasyStateAction(
        string $action,
        array $commandOptions,
        string $failureMessage,
        array $transportOptions = [],
    ): void {
        if (! $this->localExecutor instanceof RemoteLocalExecutor || ! $this->vpnNodeResolver instanceof VpnNodeResolver) {
            throw new WgEasyStatePreflightFailed($failureMessage);
        }

        $result = $this->localExecutor->runInternal(
            node: $this->vpnNodeResolver->activeVpnNode(),
            commandName: self::WG_EASY_STATE_COMMAND,
            arguments: [],
            commandOptions: [
                'action' => $action,
                ...$commandOptions,
            ],
            transportOptions: [
                'timeout' => 30,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => (string) Str::uuid(),
                ],
                ...$transportOptions,
            ],
        );

        $this->assertWgEasyStateSucceeded($result, $failureMessage);
    }

    private function assertWgEasyStateSucceeded(RemoteShellResult $result, string $failureMessage): void
    {
        $envelope = $this->wgEasyStateEnvelope($result, $failureMessage);

        if ($result->successful() && is_array($envelope['success'] ?? null)) {
            return;
        }

        throw $this->wgEasyStateFailure($failureMessage, $envelope);
    }

    /**
     * @return array<string, mixed>
     */
    private function wgEasyStateEnvelope(RemoteShellResult $result, string $failureMessage): array
    {
        try {
            $decoded = json_decode(trim($result->stdout), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WgEasyStatePreflightFailed($failureMessage);
        }

        if (! is_array($decoded) || ! (array_key_exists('success', $decoded) || array_key_exists('error', $decoded))) {
            throw new WgEasyStatePreflightFailed($failureMessage);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function wgEasyStateFailure(string $failureMessage, array $envelope): WgEasyStatePreflightFailed
    {
        $error = is_array($envelope['error'] ?? null) ? $envelope['error'] : [];
        $code = $this->safeWgEasyStateErrorCode($error['code'] ?? null);

        if ($code === null) {
            return new WgEasyStatePreflightFailed($failureMessage);
        }

        return new WgEasyStatePreflightFailed($failureMessage, [
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

    private function updateEnvironmentPassword(string $password): void
    {
        $envPath = app()->environmentFilePath();

        if (! file_exists($envPath)) {
            return;
        }

        $contents = (string) file_get_contents($envPath);
        $quoted = '"'.addcslashes($password, '"\\').'"';

        if (str_contains($contents, 'WG_EASY_PASSWORD=')) {
            $contents = (string) preg_replace('/^WG_EASY_PASSWORD=.*/m', "WG_EASY_PASSWORD={$quoted}", $contents);
        } else {
            $contents = rtrim($contents)."\nWG_EASY_PASSWORD={$quoted}\n";
        }

        file_put_contents($envPath, $contents);
    }
}

final class WgEasyStatePreflightFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(string $message, public readonly array $meta = [])
    {
        parent::__construct($message);
    }
}
