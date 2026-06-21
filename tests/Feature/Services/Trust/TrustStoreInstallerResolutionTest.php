<?php

declare(strict_types=1);

use App\Services\Trust\LinuxTrustStoreInstaller;
use App\Services\Trust\MacOsTrustStoreInstaller;
use App\Services\Trust\TrustStoreInstaller;
use App\Support\LocalPlatform;

describe('TrustStoreInstaller container resolution', function (): void {
    it('resolves MacOsTrustStoreInstaller when platform is macos', function (): void {
        app()->instance(LocalPlatform::class, new class extends LocalPlatform
        {
            public function current(): string
            {
                return 'macos';
            }
        });

        $installer = app(TrustStoreInstaller::class);

        expect($installer)->toBeInstanceOf(MacOsTrustStoreInstaller::class);
    });

    it('resolves LinuxTrustStoreInstaller when platform is linux', function (): void {
        app()->instance(LocalPlatform::class, new class extends LocalPlatform
        {
            public function current(): string
            {
                return 'linux';
            }
        });

        $installer = app(TrustStoreInstaller::class);

        expect($installer)->toBeInstanceOf(LinuxTrustStoreInstaller::class);
    });

    it('throws RuntimeException on unsupported platform', function (): void {
        app()->instance(LocalPlatform::class, new class extends LocalPlatform
        {
            public function current(): string
            {
                return 'unsupported';
            }
        });

        expect(fn () => app(TrustStoreInstaller::class))
            ->toThrow(RuntimeException::class, 'Unsupported platform');
    });
});
