<?php

declare(strict_types=1);

use App\Data\Security\PinnedHostKey;
use App\Enums\Gateway\GatewayExposureMode;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\WireGuardPeer;
use App\Services\Ca\OrbitCaService;
use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmInstaller;
use App\Services\Security\SshHostKeyPinner;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

describe('orbit:internal:bootstrap-gateway-local', function (): void {
    beforeEach(function (): void {
        $this->tempStorage = sys_get_temp_dir().'/orbit-ca-test-'.uniqid();
        mkdir($this->tempStorage.'/app/orbit', 0777, true);
        app()->useStoragePath($this->tempStorage);
        $this->originalEnvironmentPath = app()->environmentPath();
        app()->useEnvironmentPath($this->tempStorage);
        File::put("{$this->tempStorage}/.env", "APP_NAME=Orbit\n");

        app()->instance(OrbitCaService::class, new readonly class extends OrbitCaService
        {
            public function ensureRootCa(): void
            {
                File::ensureDirectoryExists(storage_path('app/orbit/ca'));
                File::put(storage_path('app/orbit/ca/root.key'), 'test-root-key');
                File::put(storage_path('app/orbit/ca/root.crt'), $this->rootCert());
            }

            public function rootCert(): string
            {
                return "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n";
            }
        });

        $this->gatewaySwarmInstaller = new class extends GatewaySwarmInstaller
        {
            /**
             * @var list<array{
             *     wireguardAddress: string,
             *     image: string,
             *     exposureMode: string,
             *     configRoot: string|null,
             *     wireguardCidr: string,
             *     wireguardInterface: string,
             *     imageArchive: string|null
             * }>
             */
            public array $installs = [];

            public function __construct() {}

            public function install(
                string $wireguardAddress,
                GatewayImageReference $image,
                GatewayExposureMode $exposureMode,
                ?string $configRoot = null,
                string $wireguardCidr = '10.6.0.0/24',
                string $wireguardInterface = 'wg-orbit',
                ?string $imageArchive = null,
            ): void {
                $this->installs[] = [
                    'wireguardAddress' => $wireguardAddress,
                    'image' => $image->canonical(),
                    'exposureMode' => $exposureMode->value,
                    'configRoot' => $configRoot,
                    'wireguardCidr' => $wireguardCidr,
                    'wireguardInterface' => $wireguardInterface,
                    'imageArchive' => $imageArchive,
                ];
            }
        };

        $this->legacyGatewayApiContainerInstaller = new class
        {
            /** @var list<string> */
            public array $addresses = [];

            public function install(string $wireguardAddress): void
            {
                $this->addresses[] = $wireguardAddress;
            }
        };

        $this->vpnDnsSwarmInstaller = new class extends VpnDnsSwarmInstaller
        {
            /** @var list<array{publicHost: string, username: string, password: string, wireguardCidr: string, wireguardPort: int, dnsIp: string}> */
            public array $invocations = [];

            /** @var list<array{name: string, private_key: string, public_key: string, pre_shared_key: string, address: string}> */
            public array $peers = [];

            public function __construct() {}

            public function install(
                string $publicHost,
                string $username,
                string $password,
                string $wireguardCidr = '10.6.0.0/24',
                int $wireguardPort = 51820,
                string $dnsIp = '10.6.0.1',
            ): void {
                $this->invocations[] = [
                    'publicHost' => $publicHost,
                    'username' => $username,
                    'password' => $password,
                    'wireguardCidr' => $wireguardCidr,
                    'wireguardPort' => $wireguardPort,
                    'dnsIp' => $dnsIp,
                ];
            }

            public function publicKey(): string
            {
                return 'wg-easy-public-key';
            }

            public function configurePeers(array $peers): void
            {
                $this->peers = $peers;
            }
        };

        $this->hostKeyPinner = new class
        {
            /** @var list<array{host: string, expected: string|null}> */
            public array $calls = [];

            public function pin(string $host, ?string $expectedFingerprint = null): PinnedHostKey
            {
                $this->calls[] = ['host' => $host, 'expected' => $expectedFingerprint];

                return new PinnedHostKey(
                    host: $host,
                    type: 'ssh-ed25519',
                    publicKey: 'AAAAC3NzaC1lZDI1NTE5AAAAIGatewayBootstrapLocalHostKey',
                    fingerprint: $expectedFingerprint ?? 'SHA256:gateway-bootstrap-local',
                    pinMode: $expectedFingerprint === null ? 'tofu' : 'verified',
                );
            }
        };

        app()->instance(GatewaySwarmInstaller::class, $this->gatewaySwarmInstaller);
        app()->instance(VpnDnsSwarmInstaller::class, $this->vpnDnsSwarmInstaller);
        app()->instance(SshHostKeyPinner::class, $this->hostKeyPinner);
    });

    afterEach(function (): void {
        if (isset($this->originalEnvironmentPath)) {
            app()->useEnvironmentPath($this->originalEnvironmentPath);
        }

        if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
            File::deleteDirectory($this->tempStorage);
        }
    });

    it('creates a local gateway node record and generates the root CA', function (): void {
        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--public-host' => '203.0.113.10',
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and(Node::query()->where('name', 'gateway-1')->exists())->toBeTrue()
            ->and(Node::query()->where('name', 'gateway-1')->value('platform'))->toBe('ubuntu')
            ->and(NodeRoleAssignment::query()
                ->whereHas('node', fn ($query) => $query->where('name', 'gateway-1'))
                ->orderBy('role')
                ->pluck('role')
                ->all())->toBe(['gateway', 'router', 'vpn'])
            ->and(Node::query()->where('name', 'gateway-1')->value('host_key_type'))->toBe('ssh-ed25519')
            ->and(Node::query()->where('name', 'gateway-1')->value('host_key_fingerprint'))->toBe('SHA256:gateway-bootstrap-local')
            ->and($this->hostKeyPinner->calls)->toBe([
                ['host' => '203.0.113.10', 'expected' => null],
            ])
            ->and(NodeRoleAssignment::query()
                ->whereHas('node', fn ($query) => $query->where('name', 'gateway-1'))
                ->where('role', 'vpn')
                ->first()?->settings)->toBe([
                    'public_endpoint' => '203.0.113.10',
                    'wireguard_cidr' => '10.6.0.0/24',
                    'wireguard_port' => 51820,
                    'dns_ip' => '10.6.0.1',
                ])
            ->and($output)->toContain('-----BEGIN CERTIFICATE-----')
            ->and($output)->toContain('-----END CERTIFICATE-----')
            ->and($this->gatewaySwarmInstaller->installs)->toMatchArray([
                [
                    'wireguardAddress' => '10.6.0.2',
                    'image' => 'orbit-gateway:current',
                    'exposureMode' => 'router-colocated',
                    'configRoot' => (string) config('orbit.paths.config_root'),
                    'wireguardCidr' => '10.6.0.0/24',
                    'wireguardInterface' => 'wg-orbit',
                    'imageArchive' => null,
                ],
            ]);
    });

    it('can skip gateway service installation for container topology preparation', function (): void {
        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--skip-gateway-service-install' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and(Node::query()->where('name', 'gateway-1')->exists())->toBeTrue()
            ->and($this->gatewaySwarmInstaller->installs)->toBe([])
            ->and($this->vpnDnsSwarmInstaller->invocations)->toBe([]);
    });

    it('keeps gateway bootstrap aligned with the host launcher install contract', function (): void {
        $installer = File::get(repo_path('bin/install-orbit'));

        expect($installer)->toContain('ln -sf "$TARGET_DIR/bin/orbit-binary" "$LINK_PATH"')
            ->and($installer)->not->toContain('ln -sf "$TARGET_DIR/artisan" "$LINK_PATH"');
    });

    it('installs wg-easy before orbit-dns after the gateway API service', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--public-host' => '203.0.113.10',
        ]);

        expect($this->gatewaySwarmInstaller->installs)->toHaveCount(1)
            ->and($this->vpnDnsSwarmInstaller->invocations)->toHaveCount(1)
            ->and($this->vpnDnsSwarmInstaller->invocations[0]['publicHost'])->toBe('203.0.113.10')
            ->and($this->vpnDnsSwarmInstaller->invocations[0]['username'])->toBe('orbit')
            ->and($this->vpnDnsSwarmInstaller->invocations[0]['password'])->not->toBe('');
    });

    it('passes configured gateway image and archive to the Swarm installer', function (): void {
        config()->set('orbit.updates.gateway_image', 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        config()->set('orbit.updates.gateway_image_archive', '/var/tmp/orbit-gateway-current.tar');

        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--public-host' => '203.0.113.10',
        ]);

        expect($this->gatewaySwarmInstaller->installs)->toMatchArray([
            [
                'wireguardAddress' => '10.6.0.2',
                'image' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'exposureMode' => 'router-colocated',
                'configRoot' => (string) config('orbit.paths.config_root'),
                'wireguardCidr' => '10.6.0.0/24',
                'wireguardInterface' => 'wg-orbit',
                'imageArchive' => '/var/tmp/orbit-gateway-current.tar',
            ],
        ]);
    });

    it('persists the wg-easy admin password in the gateway env', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--public-host' => '203.0.113.10',
        ]);

        $env = File::get(app()->environmentFilePath());

        expect($env)->toContain('WG_EASY_PASSWORD=');
    });

    it('uses the configured gateway environment file for bootstrap secrets', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--public-host' => '203.0.113.10',
        ]);

        $env = File::get(app()->environmentFilePath());

        expect(File::exists(app()->environmentFilePath()))->toBeTrue()
            ->and($env)->toContain('WG_EASY_PASSWORD=');
    });

    it('reuses an existing wg-easy admin password on re-bootstrap', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--public-host' => '203.0.113.10',
        ]);
        $firstPassword = $this->vpnDnsSwarmInstaller->invocations[0]['password'];

        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--public-host' => '203.0.113.10',
        ]);

        expect($this->vpnDnsSwarmInstaller->invocations[1]['password'])->toBe($firstPassword);
    });

    it('skips wg-easy and orbit-dns when public host is not provided', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        expect($this->vpnDnsSwarmInstaller->invocations)->toBe([]);
    });

    it('sets the gateway tld to "gateway" by default', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        expect(Node::query()->where('name', 'gateway-1')->value('tld'))->toBe('gateway');
    });

    it('honors a custom --tld value for the gateway', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--tld' => 'orbital',
        ]);

        expect(Node::query()->where('name', 'gateway-1')->value('tld'))->toBe('orbital');
    });

    it('is idempotent when the gateway node and CA already exist', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        $firstOutput = Artisan::output();

        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        $secondOutput = Artisan::output();

        expect($firstOutput)->toBe($secondOutput)
            ->and(Node::query()->where('name', 'gateway-1')->count())->toBe(1);
    });

    it('keeps existing operator identities role-free when creating the gateway record', function (): void {
        Node::query()->create([
            'name' => 'operator-1',
            'host' => '127.0.0.1',
            'orbit_path' => base_path(),
            'status' => 'active',
        ]);

        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        expect(Node::query()->where('name', 'operator-1')->first()?->isOperator())->toBeTrue()
            ->and(NodeRoleAssignment::query()
                ->whereHas('node', fn ($query) => $query->where('name', 'gateway-1'))
                ->orderBy('role')
                ->pluck('role')
                ->all())->toBe(['gateway', 'router', 'vpn']);
    });

    it('persists wireguard peers and configures the gateway interface idempotently', function (): void {
        $writtenConfig = null;
        $caDir = storage_path('app/orbit/ca');

        File::ensureDirectoryExists($caDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");

        Process::fake(function ($process) use (&$writtenConfig) {
            if (str_contains($process->command, 'tee /etc/wireguard/wg-orbit.conf')) {
                $writtenConfig = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        $identity = [
            'gateway' => [
                'public_key' => 'gateway-public-v1',
                'private_key' => 'gateway-private-v1',
            ],
            'control' => [
                'name' => 'mini',
                'wireguard_address' => '10.6.0.3',
                'public_key' => 'control-public-v1',
                'private_key' => 'control-private-v1',
            ],
        ];

        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--identity-json' => json_encode($identity, JSON_THROW_ON_ERROR),
        ]);

        $gateway = Node::query()->where('name', 'gateway-1')->first();
        $control = Node::query()->where('name', 'mini')->first();
        $gatewayPeer = WireGuardPeer::query()->where('node_id', $gateway?->id)->first();
        $controlPeer = WireGuardPeer::query()->where('node_id', $control?->id)->first();
        $initialGatewayGrant = NodeAccess::query()
            ->where('consumer_node_id', $control?->id)
            ->where('serving_node_id', $gateway?->id)
            ->first();

        expect($exitCode)->toBe(0)
            ->and($gateway)->toBeInstanceOf(Node::class)
            ->and($gateway->platform)->toBe('ubuntu')
            ->and($control)->toBeInstanceOf(Node::class)
            ->and($control->isOperator())->toBeTrue()
            ->and($control->wireguard_address)->toBe('10.6.0.3')
            ->and($initialGatewayGrant)->toBeInstanceOf(NodeAccess::class)
            ->and($initialGatewayGrant->permissions)->toBe(['*'])
            ->and($initialGatewayGrant->custom_permissions)->toBe([])
            ->and($gatewayPeer)->toBeInstanceOf(WireGuardPeer::class)
            ->and($gatewayPeer->public_key)->toBe('gateway-public-v1')
            ->and($gatewayPeer->private_key)->toBe('gateway-private-v1')
            ->and($gatewayPeer->allowed_ips)->toBe('10.6.0.2/32')
            ->and($controlPeer)->toBeInstanceOf(WireGuardPeer::class)
            ->and($controlPeer->public_key)->toBe('control-public-v1')
            ->and($controlPeer->private_key)->toBe('control-private-v1')
            ->and($controlPeer->allowed_ips)->toBe('10.6.0.3/32')
            ->and($writtenConfig)->toContain('PrivateKey = gateway-private-v1')
            ->and($writtenConfig)->toContain('Address = 10.6.0.2/24')
            ->and($writtenConfig)->toContain('ListenPort = 51820')
            ->and($writtenConfig)->toContain('PublicKey = control-public-v1')
            ->and($writtenConfig)->toContain('AllowedIPs = 10.6.0.3/32');

        Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo mkdir -p /etc/wireguard'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo tee /etc/wireguard/wg-orbit.conf'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo wg-quick up wg-orbit'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo systemctl enable wg-quick@wg-orbit'));

        $replacementIdentity = [
            'gateway' => [
                'public_key' => 'gateway-public-v2',
                'private_key' => 'gateway-private-v2',
            ],
            'control' => [
                'name' => 'mini',
                'wireguard_address' => '10.6.0.3',
                'public_key' => 'control-public-v2',
                'private_key' => 'control-private-v2',
            ],
        ];

        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--identity-json' => json_encode($replacementIdentity, JSON_THROW_ON_ERROR),
        ]);

        expect(Node::query()->whereIn('name', ['gateway-1', 'mini'])->count())->toBe(2)
            ->and(WireGuardPeer::query()->count())->toBe(2)
            ->and($gatewayPeer->fresh()->public_key)->toBe('gateway-public-v1')
            ->and($gatewayPeer->fresh()->private_key)->toBe('gateway-private-v1')
            ->and($controlPeer->fresh()->public_key)->toBe('control-public-v1')
            ->and($controlPeer->fresh()->private_key)->toBe('control-private-v1');
    });

    it('configures the gateway host as a wg-easy peer during first-gateway bootstrap', function (): void {
        $writtenConfig = null;
        $caDir = storage_path('app/orbit/ca');

        File::ensureDirectoryExists($caDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");

        Process::fake(function ($process) use (&$writtenConfig) {
            if (str_contains($process->command, 'tee /etc/wireguard/wg-orbit.conf')) {
                $writtenConfig = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        $identity = [
            'gateway' => [
                'public_key' => 'gateway-public-v1',
                'private_key' => 'gateway-private-v1',
                'pre_shared_key' => 'gateway-psk-v1',
            ],
            'control' => [
                'name' => 'mini',
                'wireguard_address' => '10.6.0.3',
                'public_key' => 'control-public-v1',
                'private_key' => 'control-private-v1',
                'pre_shared_key' => 'control-psk-v1',
            ],
        ];

        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--identity-json' => json_encode($identity, JSON_THROW_ON_ERROR),
            '--public-host' => '203.0.113.10',
            '--metadata-json' => true,
        ]);

        $metadata = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $gateway = Node::query()->where('name', 'gateway-1')->first();
        $control = Node::query()->where('name', 'mini')->first();
        $gatewayPeer = WireGuardPeer::query()->where('node_id', $gateway?->id)->first();
        $controlPeer = WireGuardPeer::query()->where('node_id', $control?->id)->first();

        expect($exitCode)->toBe(0)
            ->and($metadata['ca_cert'])->toContain('-----BEGIN CERTIFICATE-----')
            ->and($metadata['wireguard_server_public_key'])->toBe('wg-easy-public-key')
            ->and($gatewayPeer)->toBeInstanceOf(WireGuardPeer::class)
            ->and($gatewayPeer->pre_shared_key)->toBe('gateway-psk-v1')
            ->and($controlPeer)->toBeInstanceOf(WireGuardPeer::class)
            ->and($controlPeer->pre_shared_key)->toBe('control-psk-v1')
            ->and($this->vpnDnsSwarmInstaller->peers)->toMatchArray([
                [
                    'name' => 'gateway-1',
                    'private_key' => 'gateway-private-v1',
                    'public_key' => 'gateway-public-v1',
                    'pre_shared_key' => 'gateway-psk-v1',
                    'address' => '10.6.0.2',
                ],
                [
                    'name' => 'mini',
                    'private_key' => 'control-private-v1',
                    'public_key' => 'control-public-v1',
                    'pre_shared_key' => 'control-psk-v1',
                    'address' => '10.6.0.3',
                ],
            ])
            ->and($writtenConfig)->toContain('PrivateKey = gateway-private-v1')
            ->and($writtenConfig)->toContain('Address = 10.6.0.2/24')
            ->and($writtenConfig)->toContain('PublicKey = wg-easy-public-key')
            ->and($writtenConfig)->toContain('PresharedKey = gateway-psk-v1')
            ->and($writtenConfig)->toContain('AllowedIPs = 10.6.0.0/24')
            ->and($writtenConfig)->toContain('Endpoint = 203.0.113.10:51820')
            ->and($writtenConfig)->not->toContain('ListenPort = 51820')
            ->and($writtenConfig)->not->toContain('PublicKey = control-public-v1');
    });
});
