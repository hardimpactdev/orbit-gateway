<?php

declare(strict_types=1);

use App\Services\Trust\MacOsTrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->installer = new MacOsTrustStoreInstaller;
    $this->tempStorage = sys_get_temp_dir().'/orbit-trust-test-'.uniqid();
    mkdir($this->tempStorage.'/app/orbit/gateway-ca', 0777, true);
    app()->useStoragePath($this->tempStorage);

    $this->caPath = $this->tempStorage.'/app/orbit/gateway-ca/test.pem';
    file_put_contents($this->caPath, "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----\n");
});

afterEach(function (): void {
    if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
        File::deleteDirectory($this->tempStorage);
    }
});

describe('MacOsTrustStoreInstaller', function (): void {
    it('returns true when CA is found in keychain', function (): void {
        Process::fake([
            '*' => Process::result(''),
        ]);

        $result = $this->installer->isCaTrusted($this->caPath, 'orbit');

        expect($result)->toBeTrue();
    });

    it('returns false when CA is not found in keychain', function (): void {
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: '',
                exitCode: 1,
            ),
        ]);

        $result = $this->installer->isCaTrusted($this->caPath, 'orbit');

        expect($result)->toBeFalse();
    });

    it('trusts CA via sudo security add-trusted-cert', function (): void {
        Process::fake([
            '*' => Process::result(''),
        ]);

        $this->installer->trustCa($this->caPath, 'orbit');

        Process::assertRan(fn ($process) => str_contains($process->command, 'security add-trusted-cert'));
    });

    it('throws TrustStoreInstallException when trust command fails', function (): void {
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: 'User canceled.',
                exitCode: 1,
            ),
        ]);

        expect(fn () => $this->installer->trustCa($this->caPath, 'orbit'))
            ->toThrow(TrustStoreInstallException::class);
    });

    it('plumbs log callable through to trustCa', function (): void {
        Process::fake([
            '*' => Process::result(''),
        ]);

        $logs = [];
        $this->installer->trustCa($this->caPath, 'orbit', function (string $message) use (&$logs): void {
            $logs[] = $message;
        });

        expect($logs)->toHaveCount(1)
            ->and($logs[0])->toContain('sudo security add-trusted-cert');
    });
});
