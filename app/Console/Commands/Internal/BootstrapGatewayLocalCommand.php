<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Data\Nodes\RoleSettings\VpnRoleSettings;
use App\Data\Security\PinnedHostKey;
use App\Enums\Gateway\GatewayExposureMode;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\WireGuardPeer;
use App\Services\Ca\OrbitCaService;
use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmInstaller;
use App\Services\Security\SshHostKeyPinner;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use App\Services\WireGuard\WireGuardInterfaceInstaller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

#[Signature('orbit:internal:bootstrap-gateway-local
    {name : Gateway node name}
    {wireguard-address : WireGuard address for the gateway}
    {--identity-json= : Gateway/operator WireGuard identity payload; use - to read JSON from STDIN}
    {--public-host= : Public IPv4 or DNS name that external WG peers connect to (required to provision wg-easy/orbit-dns)}
    {--tld=gateway : TLD assigned to the gateway node; used to resolve <gateway-name>.<tld> over WG-served DNS}
    {--metadata-json : Output bootstrap metadata JSON instead of only the root CA PEM}
    {--skip-gateway-service-install : Skip orbit-caddy gateway API site write, wg-easy, and orbit-dns installation for container-only E2E topology preparation}
    {--skip-wireguard-install : Skip gateway WireGuard interface installation for Docker E2E topology preparation}')]
#[Description('Bootstrap gateway-local identity and root CA on the gateway host')]
class BootstrapGatewayLocalCommand extends Command
{
    public function handle(
        OrbitCaService $caService,
        WireGuardInterfaceInstaller $wireGuard,
        GatewaySwarmInstaller $gatewaySwarmInstaller,
        VpnDnsSwarmInstaller $vpnDnsSwarmInstaller,
    ): int {
        $name = $this->stringArgument('name');
        $wireguardAddress = $this->stringArgument('wireguard-address');
        $identity = $this->identityPayload();
        $gatewayTld = $this->stringOption('tld') ?? 'gateway';
        $publicHost = $this->stringOption('public-host');
        $hostKey = $publicHost !== null
            ? app(SshHostKeyPinner::class)->pin($publicHost)
            : null;

        if ($name === null || $wireguardAddress === null) {
            throw new RuntimeException('Name and wireguard-address are required.');
        }

        /** @var array{gateway_name: string, gateway_public_key: string, gateway_private_key: string, gateway_pre_shared_key: string|null, gateway_wireguard_address: string|null, operator_name: string, operator_public_key: string, operator_private_key: string, operator_pre_shared_key: string|null, operator_wireguard_address: string|null}|null $enrollment */
        $enrollment = DB::transaction(function () use ($name, $wireguardAddress, $identity, $gatewayTld, $publicHost, $hostKey) {
            $gateway = Node::query()->updateOrCreate(
                ['name' => $name],
                [
                    'tld' => $gatewayTld,
                    'platform' => 'ubuntu',
                    'host' => $wireguardAddress,
                    'wireguard_address' => $wireguardAddress,
                    'gateway_endpoint' => null,
                    'user' => 'orbit',
                    'orbit_path' => '/home/orbit/orbit',
                    'status' => 'active',
                    ...$this->hostKeyAttributes($hostKey),
                ],
            );

            NodeRoleAssignment::query()->updateOrCreate(
                [
                    'node_id' => $gateway->id,
                    'role' => NodeRoleName::Gateway->value,
                ],
                [
                    'status' => NodeRoleStatus::Active->value,
                    'settings' => [],
                ],
            );

            NodeRoleAssignment::query()->updateOrCreate(
                [
                    'node_id' => $gateway->id,
                    'role' => NodeRoleName::Vpn->value,
                ],
                [
                    'status' => NodeRoleStatus::Active->value,
                    'settings' => $this->vpnRoleSettings($publicHost),
                    'last_error' => null,
                    'converged_at' => now(),
                ],
            );

            NodeRoleAssignment::query()->updateOrCreate(
                [
                    'node_id' => $gateway->id,
                    'role' => NodeRoleName::Router->value,
                ],
                [
                    'status' => NodeRoleStatus::Active->value,
                    'settings' => [],
                    'last_error' => null,
                    'converged_at' => now(),
                ],
            );

            if ($identity === null) {
                return null;
            }

            $control = Node::query()->updateOrCreate(
                ['name' => $identity['control']['name']],
                [
                    'tld' => null,
                    'platform' => 'unknown',
                    'host' => $identity['control']['wireguard_address'],
                    'wireguard_address' => $identity['control']['wireguard_address'],
                    'gateway_endpoint' => $wireguardAddress,
                    'user' => 'orbit',
                    'orbit_path' => '/home/orbit/orbit',
                    'status' => 'active',
                ],
            );

            $gatewayPeer = WireGuardPeer::query()->firstOrCreate(
                ['node_id' => $gateway->id],
                [
                    'public_key' => $identity['gateway']['public_key'],
                    'private_key' => $identity['gateway']['private_key'],
                    'pre_shared_key' => $identity['gateway']['pre_shared_key'],
                    'allowed_ips' => "{$wireguardAddress}/32",
                ],
            );

            $controlPeer = WireGuardPeer::query()->firstOrCreate(
                ['node_id' => $control->id],
                [
                    'public_key' => $identity['control']['public_key'],
                    'private_key' => $identity['control']['private_key'],
                    'pre_shared_key' => $identity['control']['pre_shared_key'],
                    'allowed_ips' => "{$identity['control']['wireguard_address']}/32",
                ],
            );

            NodeAccess::query()->firstOrCreate(
                [
                    'consumer_node_id' => $control->id,
                    'serving_node_id' => $gateway->id,
                ],
                [
                    'permissions' => ['*'],
                    'custom_permissions' => [],
                ],
            );

            return [
                'gateway_name' => $gateway->name,
                'gateway_public_key' => $gatewayPeer->public_key,
                'gateway_private_key' => $gatewayPeer->private_key,
                'gateway_pre_shared_key' => $gatewayPeer->pre_shared_key,
                'gateway_wireguard_address' => $gateway->wireguard_address,
                'operator_name' => $control->name,
                'operator_public_key' => $controlPeer->public_key,
                'operator_private_key' => $controlPeer->private_key,
                'operator_pre_shared_key' => $controlPeer->pre_shared_key,
                'operator_wireguard_address' => $control->wireguard_address,
            ];
        });

        $caService->ensureRootCa();
        $wireguardServerPublicKey = null;

        if (! (bool) $this->option('skip-gateway-service-install')) {
            if ($publicHost !== null) {
                $password = $this->ensureWgEasyPassword();
                $username = (string) config('services.wg_easy.username', 'orbit');
                $vpnDnsSwarmInstaller->install(publicHost: $publicHost, username: $username, password: $password);
                $wireguardServerPublicKey = $vpnDnsSwarmInstaller->publicKey();

                if ($enrollment !== null) {
                    if ($enrollment['gateway_pre_shared_key'] === null || $enrollment['operator_pre_shared_key'] === null) {
                        throw new RuntimeException('WireGuard identity payload must include pre-shared keys when bootstrapping through wg-easy.');
                    }

                    $vpnDnsSwarmInstaller->configurePeers([
                        [
                            'name' => $enrollment['gateway_name'],
                            'private_key' => $enrollment['gateway_private_key'],
                            'public_key' => $enrollment['gateway_public_key'],
                            'pre_shared_key' => $enrollment['gateway_pre_shared_key'],
                            'address' => $enrollment['gateway_wireguard_address'],
                        ],
                        [
                            'name' => $enrollment['operator_name'],
                            'private_key' => $enrollment['operator_private_key'],
                            'public_key' => $enrollment['operator_public_key'],
                            'pre_shared_key' => $enrollment['operator_pre_shared_key'],
                            'address' => $enrollment['operator_wireguard_address'],
                        ],
                    ]);
                }
            }
        }

        if ($enrollment !== null && ! (bool) $this->option('skip-wireguard-install')) {
            $wireGuard->install($wireguardServerPublicKey !== null && $publicHost !== null
                ? $this->gatewayClientWireGuardConfig(
                    gatewayPrivateKey: $enrollment['gateway_private_key'],
                    gatewayWireguardAddress: $wireguardAddress,
                    wireguardServerPublicKey: $wireguardServerPublicKey,
                    preSharedKey: $enrollment['gateway_pre_shared_key'],
                    endpoint: $publicHost,
                )
                : $this->gatewayWireGuardConfig(
                    gatewayPrivateKey: $enrollment['gateway_private_key'],
                    gatewayWireguardAddress: $wireguardAddress,
                    controlPublicKey: $enrollment['operator_public_key'],
                    controlWireguardAddress: $enrollment['operator_wireguard_address'],
                ));
        }

        if (! (bool) $this->option('skip-gateway-service-install')) {
            $gatewaySwarmInstaller->install(
                wireguardAddress: $wireguardAddress,
                image: $this->gatewayImage(),
                exposureMode: $this->gatewayExposureMode(),
                configRoot: (string) config('orbit.paths.config_root'),
                wireguardCidr: '10.6.0.0/24',
                wireguardInterface: 'wg-orbit',
                imageArchive: $this->gatewayImageArchive(),
            );
        }

        if ((bool) $this->option('metadata-json')) {
            $this->line(json_encode([
                'ca_cert' => $caService->rootCert(),
                'wireguard_server_public_key' => $wireguardServerPublicKey,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line($caService->rootCert());

        return self::SUCCESS;
    }

    private function gatewayImage(): GatewayImageReference
    {
        $image = config('orbit.updates.gateway_image');

        if (! is_string($image) || trim($image) === '') {
            $image = 'orbit-gateway:current';
        }

        return GatewayImageReference::fromString($image);
    }

    private function gatewayImageArchive(): ?string
    {
        $archive = config('orbit.updates.gateway_image_archive');

        if (! is_string($archive) || trim($archive) === '') {
            return null;
        }

        return trim($archive);
    }

    private function gatewayExposureMode(): GatewayExposureMode
    {
        $mode = config('orbit.gateway.exposure_mode', GatewayExposureMode::RouterColocated->value);

        return GatewayExposureMode::parse((string) $mode);
    }

    private function ensureWgEasyPassword(): string
    {
        $existing = $this->readEnvVar('WG_EASY_PASSWORD');

        if ($existing !== null) {
            return $existing;
        }

        $password = Str::random(32);

        $this->writeEnvVar('WG_EASY_PASSWORD', $password);
        config(['services.wg_easy.password' => $password]);

        return $password;
    }

    /**
     * @return array<string, mixed>
     */
    private function hostKeyAttributes(?PinnedHostKey $hostKey): array
    {
        if (! $hostKey instanceof PinnedHostKey) {
            return [];
        }

        return [
            'host_key_type' => $hostKey->type,
            'host_key_public' => $hostKey->publicKey,
            'host_key_fingerprint' => $hostKey->fingerprint,
            'host_key_pin_mode' => $hostKey->pinMode,
            'host_key_pinned_at' => now(),
        ];
    }

    /**
     * @return array{public_endpoint: ?string, wireguard_cidr: string, wireguard_port: int, dns_ip: string}
     */
    private function vpnRoleSettings(?string $publicHost): array
    {
        return VpnRoleSettings::fromArray([
            'public_endpoint' => $publicHost,
            'wireguard_cidr' => '10.6.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.6.0.1',
        ])->toArray();
    }

    private function readEnvVar(string $key): ?string
    {
        $path = app()->environmentFilePath();

        if (! File::exists($path)) {
            return null;
        }

        $contents = File::get($path);

        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches) === 1) {
            $value = trim($matches[1]);

            if ($value === '') {
                return null;
            }

            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                return stripcslashes(substr($value, 1, -1));
            }

            if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                return str_replace("\\'", "'", substr($value, 1, -1));
            }

            return $value;
        }

        return null;
    }

    private function writeEnvVar(string $key, string $value): void
    {
        $path = app()->environmentFilePath();
        $contents = File::exists($path) ? File::get($path) : '';
        $line = "{$key}={$value}";

        if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents) === 1) {
            $contents = (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents);
        } else {
            $contents = rtrim($contents)."\n{$line}\n";
        }

        File::put($path, $contents);
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{
     *     gateway: array{public_key: string, private_key: string, pre_shared_key: ?string},
     *     control: array{name: string, wireguard_address: string, public_key: string, private_key: string, pre_shared_key: ?string}
     * }|null
     */
    private function identityPayload(): ?array
    {
        $value = $this->option('identity-json');

        if (! is_string($value) || $value === '') {
            return null;
        }

        $json = $value === '-' ? stream_get_contents(STDIN) : $value;

        if (! is_string($json) || trim($json) === '') {
            throw new RuntimeException('WireGuard identity payload is required when --identity-json is set.');
        }

        try {
            $payload = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('WireGuard identity payload must be valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('WireGuard identity payload must be a JSON object.');
        }

        return [
            'gateway' => [
                'public_key' => $this->payloadString($payload, 'gateway.public_key'),
                'private_key' => $this->payloadString($payload, 'gateway.private_key'),
                'pre_shared_key' => $this->payloadOptionalString($payload, 'gateway.pre_shared_key'),
            ],
            'control' => [
                'name' => $this->payloadString($payload, 'control.name'),
                'wireguard_address' => $this->payloadString($payload, 'control.wireguard_address'),
                'public_key' => $this->payloadString($payload, 'control.public_key'),
                'private_key' => $this->payloadString($payload, 'control.private_key'),
                'pre_shared_key' => $this->payloadOptionalString($payload, 'control.pre_shared_key'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadString(array $payload, string $key): string
    {
        $value = data_get($payload, $key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("WireGuard identity payload is missing {$key}.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadOptionalString(array $payload, string $key): ?string
    {
        $value = data_get($payload, $key);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("WireGuard identity payload has invalid {$key}.");
        }

        return $value;
    }

    private function gatewayClientWireGuardConfig(
        string $gatewayPrivateKey,
        string $gatewayWireguardAddress,
        string $wireguardServerPublicKey,
        ?string $preSharedKey,
        string $endpoint,
    ): string {
        $lines = [
            '[Interface]',
            "PrivateKey = {$gatewayPrivateKey}",
            "Address = {$gatewayWireguardAddress}/24",
            '',
            '[Peer]',
            "PublicKey = {$wireguardServerPublicKey}",
        ];

        if ($preSharedKey !== null) {
            $lines[] = "PresharedKey = {$preSharedKey}";
        }

        return implode("\n", [
            ...$lines,
            'AllowedIPs = 10.6.0.0/24',
            "Endpoint = {$endpoint}:51820",
            'PersistentKeepalive = 25',
            '',
        ]);
    }

    private function gatewayWireGuardConfig(
        string $gatewayPrivateKey,
        string $gatewayWireguardAddress,
        string $controlPublicKey,
        string $controlWireguardAddress,
    ): string {
        return implode("\n", [
            '[Interface]',
            "PrivateKey = {$gatewayPrivateKey}",
            "Address = {$gatewayWireguardAddress}/24",
            'ListenPort = 51820',
            '',
            '[Peer]',
            "PublicKey = {$controlPublicKey}",
            "AllowedIPs = {$controlWireguardAddress}/32",
            'PersistentKeepalive = 25',
            '',
        ]);
    }
}
