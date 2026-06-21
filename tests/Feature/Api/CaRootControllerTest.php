<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

describe('GET /api/ca/root', function (): void {
    beforeEach(function (): void {
        $this->tempStorage = sys_get_temp_dir().'/orbit-api-ca-test-'.uniqid();
        app()->useStoragePath($this->tempStorage);
        $this->tempConfigRoot = "{$this->tempStorage}/config";
        File::ensureDirectoryExists("{$this->tempConfigRoot}/ca");
        config()->set('orbit.paths.config_root', $this->tempConfigRoot);
    });

    afterEach(function (): void {
        if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
            File::deleteDirectory($this->tempStorage);
        }
    });

    it('returns success envelope with root_ca PEM', function (): void {
        Node::factory()->gateway()->create([
            'name' => 'gateway-1',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
        ]);

        $pem = "-----BEGIN CERTIFICATE-----\nTESTPEM\n-----END CERTIFICATE-----\n";
        $caDir = "{$this->tempConfigRoot}/ca";
        file_put_contents("{$caDir}/root.crt", $pem);
        file_put_contents("{$caDir}/root.key", 'dummy-key');

        $response = $this->getJson('/api/ca/root');

        $response->assertOk()
            ->assertExactJson([
                'success' => [
                    'data' => [
                        'root_ca' => $pem,
                    ],
                ],
            ]);
    });

    it('returns error envelope when CA is not bootstrapped', function (): void {
        Node::factory()->gateway()->create([
            'name' => 'gateway-1',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
        ]);

        $response = $this->getJson('/api/ca/root');

        $response->assertStatus(503)
            ->assertJson([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway root CA is not available.',
                    'meta' => [
                        'reason' => 'ca_not_bootstrapped',
                    ],
                ],
            ]);
    });
});
