<?php

declare(strict_types=1);

use App\Services\Trust\LinuxTrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->installer = new LinuxTrustStoreInstaller;
    $this->tempStorage = sys_get_temp_dir().'/orbit-trust-test-'.uniqid();
    $this->label = 'orbit-test-'.uniqid();
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

describe('LinuxTrustStoreInstaller', function (): void {
    it('checks if CA is trusted via file existence', function (): void {
        expect($this->installer->isCaTrusted($this->caPath, $this->label))->toBeFalse();
    });

    it('trusts CA via sudo cp and update-ca-certificates', function (): void {
        Process::fake([
            '*' => Process::result(''),
        ]);

        $this->installer->trustCa($this->caPath, $this->label);

        Process::assertRan(fn ($process) => str_contains($process->command, 'sudo cp')
            && str_contains($process->command, 'sudo update-ca-certificates'));
    });

    it('throws TrustStoreInstallException when trust command fails', function (): void {
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: 'Permission denied.',
                exitCode: 1,
            ),
        ]);

        expect(fn () => $this->installer->trustCa($this->caPath, $this->label))
            ->toThrow(TrustStoreInstallException::class);
    });

    it('plumbs log callable through to trustCa', function (): void {
        Process::fake([
            '*' => Process::result(''),
        ]);

        $logs = [];
        $this->installer->trustCa($this->caPath, $this->label, function (string $message) use (&$logs): void {
            $logs[] = $message;
        });

        expect($logs)->toHaveCount(1)
            ->and($logs[0])->toContain('cp');
    });
});
