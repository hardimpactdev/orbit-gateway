<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Ca\OrbitCaService;
use App\Services\WebSockets\WebSocketBackendName;
use App\Services\WebSockets\WebSocketCertificateInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tempStorage = sys_get_temp_dir().'/orbit-websocket-cert-test-'.uniqid();
    mkdir($this->tempStorage.'/app/orbit', 0777, true);
    app()->useStoragePath($this->tempStorage);
});

afterEach(function (): void {
    if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
        File::deleteDirectory($this->tempStorage);
    }
});

it('installs backend TLS material into the host Orbit cert directory', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'wireguard_address' => '10.6.0.44',
    ]);
    $issued = new ArrayObject;
    $ca = new WebSocketCertificateInstallerTestCa($issued);
    $shell = new WebSocketCertificateInstallerTestShell;

    $paths = (new WebSocketCertificateInstaller($ca, $shell, new WebSocketBackendName))->ensureFor($node);

    $script = $shell->scripts[0];

    expect($paths)->toBe([
        'cert' => '/etc/orbit/certs/10.6.0.44.crt',
        'key' => '/etc/orbit/certs/10.6.0.44.key',
    ])
        ->and($issued->getArrayCopy())->toBe([
            ['host' => '10.6.0.44', 'additional_sans' => ['10.6.0.44']],
        ])
        ->and($shell->nodes[0]->is($node))->toBeTrue()
        ->and($shell->options[0])->toMatchArray([
            'throw' => true,
            'metadata' => [
                'ORBIT_OPERATION_ID' => 'websocket-certificate-install',
            ],
        ])
        ->and($script)->toContain("sudo install -d -m 0755 '/etc/orbit/certs'")
        ->and($script)->toContain("sudo chmod 0644 '/etc/orbit/certs/10.6.0.44.crt'")
        ->and($script)->toContain("sudo chmod 0600 '/etc/orbit/certs/10.6.0.44.key'")
        ->and($script)->not->toContain('.config/orbit/certs')
        ->and($script)->not->toContain('php artisan')
        ->and($script)->not->toContain('docker exec');
});

it('requires a WireGuard address before installing backend TLS material', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'wireguard_address' => '',
    ]);

    expect(fn () => (new WebSocketCertificateInstaller(
        new WebSocketCertificateInstallerTestCa(new ArrayObject),
        new WebSocketCertificateInstallerTestShell,
        new WebSocketBackendName,
    ))->ensureFor($node))->toThrow(RuntimeException::class, 'The websocket backend requires a WireGuard address.');
});

it('resolves expected backend certificate paths without installing material', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'wireguard_address' => '10.6.0.45',
    ]);
    $shell = new WebSocketCertificateInstallerTestShell;

    $paths = (new WebSocketCertificateInstaller(
        new WebSocketCertificateInstallerTestCa(new ArrayObject),
        $shell,
        new WebSocketBackendName,
    ))->expectedPathsFor($node);

    expect($paths)->toBe([
        'cert' => '/etc/orbit/certs/10.6.0.45.crt',
        'key' => '/etc/orbit/certs/10.6.0.45.key',
    ])
        ->and($shell->scripts)->toBe([]);
});

it('rejects nodes without a backend WireGuard address', function (): void {
    $node = Node::factory()->make(['wireguard_address' => '']);

    expect(fn () => (new WebSocketCertificateInstaller(
        new WebSocketCertificateInstallerTestCa(new ArrayObject),
        new WebSocketCertificateInstallerTestShell,
        new WebSocketBackendName,
    ))->expectedPathsFor($node))->toThrow(RuntimeException::class, 'The websocket backend requires a WireGuard address.');
});

readonly class WebSocketCertificateInstallerTestCa extends OrbitCaService
{
    public function __construct(
        private ArrayObject $issued,
    ) {}

    /**
     * @param  list<string>  $additionalSans
     * @return array{cert: string, key: string}
     */
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        $this->issued->append([
            'host' => $host,
            'additional_sans' => $additionalSans,
        ]);

        $certsDir = storage_path('app/orbit/certs');
        File::ensureDirectoryExists($certsDir);

        $certPath = "{$certsDir}/{$host}.crt";
        $keyPath = "{$certsDir}/{$host}.key";

        File::put($certPath, "certificate for {$host}");
        File::put($keyPath, "key for {$host}");

        return ['cert' => $certPath, 'key' => $keyPath];
    }
}

final class WebSocketCertificateInstallerTestShell implements RemoteShell
{
    /**
     * @var list<Node>
     */
    public array $nodes = [];

    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;
        $this->options[] = $options;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
