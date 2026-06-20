<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

describe('install-orbit gateway bootstrap', function (): void {
    beforeEach(function (): void {
        $this->installer = File::get(repo_path('bin/install-orbit'));
    });

    it('does not keep the retired install-time long-running gateway container helper', function (): void {
        expect($this->installer)
            ->not->toContain('start'.'_runtime_container')
            ->not->toContain('start_gateway_container')
            ->not->toContain('docker_cli run -d')
            ->not->toContain('--restart unless-stopped')
            ->not->toContain('ORBIT_TRUST_WIREGUARD_PROXY_HEADER');
    });

    it('bootstraps gateway state and migrations through disposable orbit-gateway containers', function (): void {
        $bootstrapIndex = strpos($this->installer, 'bootstrap_gateway_state()');
        $migrationIndex = strpos($this->installer, 'run_migrations_in_gateway_image()');

        expect($bootstrapIndex)->not->toBeFalse()
            ->and($migrationIndex)->not->toBeFalse()
            ->and($bootstrapIndex)->toBeLessThan($migrationIndex)
            ->and($this->installer)->toContain('docker_cli run --rm')
            ->and($this->installer)->toContain('--pull never')
            ->and($this->installer)->toContain('"$GATEWAY_IMAGE"')
            ->and($this->installer)->toContain('artisan --version')
            ->and($this->installer)->toContain('migrate --no-interaction --path=/srv/orbit/apps/gateway/database/migrations --realpath');
    });

    it('mounts only the config root into disposable gateway image commands', function (): void {
        expect($this->installer)
            ->toContain('--env "ORBIT_CONFIG_ROOT=$CONFIG_ROOT"')
            ->toContain('--mount "type=bind,source=$CONFIG_ROOT,target=$CONFIG_ROOT"')
            ->not->toContain('-v "$TARGET_DIR":/opt/orbit')
            ->not->toContain('target=/opt/orbit')
            ->not->toContain('php /opt/orbit/artisan migrate --force --no-interaction');
    });
});
