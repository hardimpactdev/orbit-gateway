<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Ca\OrbitCaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

function orbitCaServiceTestSeedRootFiles(string $rootCrt = "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n", string $rootKey = 'test-root-key'): void
{
    $caDir = orbitCaServiceTestCaDir();

    File::ensureDirectoryExists($caDir);
    File::put("{$caDir}/root.crt", $rootCrt);
    File::put("{$caDir}/root.key", $rootKey);
    chmod("{$caDir}/root.key", 0600);
}

function orbitCaServiceTestConfigRoot(): string
{
    $configRoot = config('orbit.paths.config_root');

    expect($configRoot)->toBeString();

    return rtrim($configRoot, '/');
}

function orbitCaServiceTestCaDir(): string
{
    return orbitCaServiceTestConfigRoot().'/ca';
}

function orbitCaServiceTestSeedValidRootFixture(): void
{
    static $fixture = null;

    if ($fixture === null) {
        $fixtureDir = sys_get_temp_dir().'/orbit-ca-service-fixture-'.getmypid();
        File::ensureDirectoryExists($fixtureDir);

        $rootKey = "{$fixtureDir}/root.key";
        $rootCrt = "{$fixtureDir}/root.crt";

        if (! File::exists($rootKey) || ! File::exists($rootCrt)) {
            $factory = new Factory;
            $factory->run(sprintf('openssl genrsa -out %s 2048', escapeshellarg($rootKey)))->throw();
            $factory->run(implode(' ', [
                'openssl req -x509 -new -nodes',
                '-key '.escapeshellarg($rootKey),
                '-sha256 -days 3650',
                '-out '.escapeshellarg($rootCrt),
                '-subj '.escapeshellarg('/CN=Orbit Test Root CA/O=Orbit Tests'),
            ]))->throw();
        }

        $fixture = [
            'crt' => File::get($rootCrt),
            'key' => File::get($rootKey),
        ];
    }

    orbitCaServiceTestSeedRootFiles($fixture['crt'], $fixture['key']);
}

function orbitCaServiceTestCreateGatewayNode(): Node
{
    return Node::factory()->gateway()->create([
        'name' => 'test-gateway',
        'status' => 'active',
        'host' => '10.6.0.1',
        'orbit_path' => '/home/orbit/orbit',
    ]);
}

describe('OrbitCaService', function () {
    beforeEach(function () {
        $this->tempStorage = sys_get_temp_dir().'/orbit-ca-test-'.uniqid();
        app()->useStoragePath($this->tempStorage);
        $this->tempConfigRoot = "{$this->tempStorage}/config";
        File::ensureDirectoryExists($this->tempConfigRoot);
        config(['orbit.paths.config_root' => $this->tempConfigRoot]);
        Process::swap(new Factory);
    });

    afterEach(function () {
        if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
            File::deleteDirectory($this->tempStorage);
        }
    });

    describe('ensureRootCa()', function () {
        it('generates root.crt and root.key on a local gateway node', function () {
            orbitCaServiceTestCreateGatewayNode();

            $service = new OrbitCaService;
            $caDir = orbitCaServiceTestCaDir();

            orbitCaServiceTestSeedRootFiles();

            $service->ensureRootCa();

            expect(File::exists("{$caDir}/root.crt"))->toBeTrue();
            expect(File::exists("{$caDir}/root.key"))->toBeTrue();
            expect(decoct(fileperms("{$caDir}/root.key") & 0777))->toBe('600');
        });

        it('is idempotent: running twice leaves files unchanged', function () {
            orbitCaServiceTestCreateGatewayNode();

            $service = new OrbitCaService;
            $caDir = orbitCaServiceTestCaDir();

            $service->ensureRootCa();

            $crtBefore = File::get("{$caDir}/root.crt");
            $keyBefore = File::get("{$caDir}/root.key");

            $service->ensureRootCa();

            expect(File::get("{$caDir}/root.crt"))->toBe($crtBefore);
            expect(File::get("{$caDir}/root.key"))->toBe($keyBefore);
        });

        it('throws with "restore" message when only root.crt exists', function () {
            orbitCaServiceTestCreateGatewayNode();

            $service = new OrbitCaService;
            $caDir = orbitCaServiceTestCaDir();

            File::ensureDirectoryExists($caDir);
            File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
            $crtContent = File::get("{$caDir}/root.crt");

            expect(fn () => $service->ensureRootCa())
                ->toThrow(RuntimeException::class, 'restore');

            expect(File::get("{$caDir}/root.crt"))->toBe($crtContent);
        });

        it('throws mentioning "gateway" when no local gateway node exists', function () {
            Node::create([
                'name' => 'not-a-gateway',
                'status' => 'active',
                'host' => '127.0.0.1',
                'orbit_path' => base_path(),
            ]);

            $service = new OrbitCaService;

            expect(fn () => $service->ensureRootCa())
                ->toThrow(RuntimeException::class, 'gateway');
        });
    });

    describe('issueLeaf()', function () {
        beforeEach(function () {
            orbitCaServiceTestCreateGatewayNode();

            orbitCaServiceTestSeedValidRootFixture();
        });

        it('issues a runtime-private leaf cert for a DNS host and returns correct paths', function () {
            $service = new OrbitCaService;
            $dataPath = orbitCaServiceTestConfigRoot();

            $paths = $service->issueLeaf('demo.beast');

            expect($paths['cert'])->toBe("{$dataPath}/certs/demo.beast.crt");
            expect($paths['key'])->toBe("{$dataPath}/certs/demo.beast.key");
            expect(File::exists($paths['cert']))->toBeTrue();
            expect(File::exists($paths['key']))->toBeTrue();
            expect(decoct(fileperms($paths['key']) & 0777))->toBe('600');

            $caDir = "{$dataPath}/ca";
            $verify = (new Factory)->run(
                sprintf('openssl verify -CAfile %s %s', escapeshellarg("{$caDir}/root.crt"), escapeshellarg($paths['cert']))
            );
            expect($verify->successful())->toBeTrue();
        });

        it('is idempotent: calling twice within freshness window returns same serial', function () {
            $service = new OrbitCaService;
            $dataPath = orbitCaServiceTestConfigRoot();

            $paths1 = $service->issueLeaf('demo.beast');
            $paths2 = $service->issueLeaf('demo.beast');

            $factory = new Factory;

            $serial1 = $factory->run(sprintf('openssl x509 -in %s -serial -noout', escapeshellarg($paths1['cert'])))->output();
            $serial2 = $factory->run(sprintf('openssl x509 -in %s -serial -noout', escapeshellarg($paths2['cert'])))->output();

            expect(trim($serial1))->toBe(trim($serial2));
        });

        it('embeds IP SAN for an IP host', function () {
            $service = new OrbitCaService;
            $paths = $service->issueLeaf('10.0.0.1');

            $factory = new Factory;
            $text = $factory->run(sprintf('openssl x509 -in %s -text -noout', escapeshellarg($paths['cert'])))->output();

            expect($text)->toContain('IP Address:10.0.0.1');
        });

        it('embeds DNS SAN for a DNS host', function () {
            $service = new OrbitCaService;
            $paths = $service->issueLeaf('demo.beast');

            $factory = new Factory;
            $text = $factory->run(sprintf('openssl x509 -in %s -text -noout', escapeshellarg($paths['cert'])))->output();

            expect($text)->toContain('DNS:demo.beast');
        });

        it('embeds additional SANs when issuing a DNS host leaf', function () {
            $service = new OrbitCaService;
            $paths = $service->issueLeaf('gateway', ['10.6.0.2']);

            $factory = new Factory;
            $text = $factory->run("openssl x509 -in {$paths['cert']} -text -noout")->output();
            $paths2 = $service->issueLeaf('gateway', ['10.6.0.2']);

            expect($text)
                ->toContain('DNS:gateway')
                ->toContain('IP Address:10.6.0.2')
                ->and($paths2)->toBe($paths);
        });

        it('reissues a fresh leaf when the requested SAN set expands', function () {
            $service = new OrbitCaService;
            $paths = $service->issueLeaf('gateway');

            $factory = new Factory;
            $initial = $factory->run("openssl x509 -in {$paths['cert']} -text -noout")->output();

            $paths2 = $service->issueLeaf('gateway', ['10.6.0.2']);
            $expanded = $factory->run("openssl x509 -in {$paths2['cert']} -text -noout")->output();

            expect($initial)->not->toContain('IP Address:10.6.0.2')
                ->and($expanded)->toContain('DNS:gateway')
                ->and($expanded)->toContain('IP Address:10.6.0.2');
        });

        it('does not treat SAN prefix matches as existing coverage', function () {
            $service = new OrbitCaService;
            $service->issueLeaf('gateway', ['10.6.0.20']);

            $paths = $service->issueLeaf('gateway', ['10.6.0.2']);

            $factory = new Factory;
            $text = $factory->run("openssl x509 -in {$paths['cert']} -text -noout")->output();

            expect($text)->toContain('DNS:gateway')
                ->and($text)->toContain('IP Address:10.6.0.2')
                ->and($text)->not->toContain('IP Address:10.6.0.20');
        });

        it('refuses path-traversal filenames', function () {
            $service = new OrbitCaService;

            expect(fn () => $service->issueLeaf('../evil'))
                ->toThrow(RuntimeException::class);
        });
    });

    describe('rootCert()', function () {
        it('returns PEM content of root.crt', function () {
            orbitCaServiceTestCreateGatewayNode();

            $caDir = orbitCaServiceTestCaDir();
            $service = new OrbitCaService;
            orbitCaServiceTestSeedRootFiles();

            $pem = $service->rootCert();

            expect($pem)->toContain('-----BEGIN CERTIFICATE-----');
            expect($pem)->toBe(File::get("{$caDir}/root.crt"));
        });

        it('throws RuntimeException when root CA is not bootstrapped', function () {
            $service = new OrbitCaService;

            expect(fn () => $service->rootCert())
                ->toThrow(RuntimeException::class);
        });
    });

    describe('gateway-local guard', function () {
        it('prevents issueLeaf on non-gateway even when CA files exist', function () {
            orbitCaServiceTestCreateGatewayNode();

            $service = new OrbitCaService;
            orbitCaServiceTestSeedRootFiles();

            Node::query()->delete();
            Node::create([
                'name' => 'test-control',
                'status' => 'active',
                'host' => '127.0.0.1',
                'orbit_path' => base_path(),
            ]);

            expect(fn () => $service->issueLeaf('demo.beast'))
                ->toThrow(RuntimeException::class, 'gateway');
        });

        it('prevents rootCert on non-gateway even when CA files exist', function () {
            orbitCaServiceTestCreateGatewayNode();

            $service = new OrbitCaService;
            orbitCaServiceTestSeedRootFiles();

            Node::query()->delete();
            Node::create([
                'name' => 'test-control',
                'status' => 'active',
                'host' => '127.0.0.1',
                'orbit_path' => base_path(),
            ]);

            expect(fn () => $service->rootCert())
                ->toThrow(RuntimeException::class, 'gateway');
        });
    });
});
