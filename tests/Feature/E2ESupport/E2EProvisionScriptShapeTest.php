<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

function provisionScript(): string
{
    return repo_path('bin/e2e-provision-node');
}

function depsScript(): string
{
    return repo_path('bin/_e2e-deps.sh');
}

function installerScript(): string
{
    return repo_path('bin/install-orbit');
}

it('ships an executable provisioner script', function (): void {
    $script = provisionScript();

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();
});

it('ships an executable deps helper', function (): void {
    $script = depsScript();

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();
});

it('prints help with --help', function (): void {
    $result = Process::run([provisionScript(), '--help']);

    expect($result->successful())->toBeTrue();
    expect($result->output())->toContain('usage: bin/e2e-provision-node');
    expect($result->output())->toContain('--node-kind=operator|gateway|app');
    expect($result->output())->toContain('--source-archive=PATH');
    expect($result->output())->toContain('Optional for app nodes when --binary is given');
    expect($result->output())->toContain('--gateway-image=IMAGE');
    expect($result->output())->toContain('--gateway-image-archive=PATH');
    expect($result->output())->toContain('--caddy-image-archive=PATH');
    expect($result->output())->toContain('--dnsmasq-image-archive=PATH');
    expect($result->output())->toContain('--frankenphp-image-archive=PATH');
    expect($result->output())->toContain('--wg-easy-image-archive=PATH');
    expect($result->output())->toContain('Topology node kind being installed');
    expect($result->output())->not->toContain('blank VM');
});

it('runs install-orbit without role semantics', function (): void {
    $provisioner = file_get_contents(provisionScript());
    $installer = file_get_contents(installerScript());

    expect($provisioner)->not->toContain('"--role=')
        ->and($provisioner)->not->toContain('--skip-prerequisites')
        ->and($provisioner)->toContain('COMPOSER_HOME=')
        ->and($installer)->toContain('ORBIT_INSTALL_SKIP_PREREQUISITES')
        ->and($installer)->toContain('--skip-prerequisites');
});

it('installs E2E base dependencies before running install-orbit', function (): void {
    $provisioner = file_get_contents(provisionScript());

    expect($provisioner)
        ->toContain('install_e2e_dependencies')
        ->toContain('"${SCRIPT_DIR}/_e2e-deps.sh" --base')
        ->not->toContain('supervisor.service');
});

it('installs host Composer dependencies after install-orbit for prepared Incus templates', function (): void {
    $provisioner = file_get_contents(provisionScript());

    expect(str_contains($provisioner, 'install_host_composer_dependencies'))->toBeTrue()
        ->and(str_contains($provisioner, 'configure_github_auth'))->toBeTrue()
        ->and(str_contains($provisioner, 'github-oauth'))->toBeTrue()
        ->and(str_contains($provisioner, 'gh auth login --hostname github.com --with-token'))->toBeTrue()
        ->and(str_contains($provisioner, 'start_step "Install host Composer dependencies"'))->toBeTrue()
        ->and(str_contains($provisioner, 'COMPOSER_CACHE_DIR="${home_dir}/.composer/cache"'))->toBeTrue()
        ->and(str_contains($provisioner, 'composer --working-dir="${target_dir}/apps/gateway" install --no-interaction --prefer-dist --optimize-autoloader --no-progress'))->toBeTrue()
        ->and(str_contains($provisioner, 'composer --working-dir="${target_dir}/apps/cli" install --no-interaction --prefer-dist --optimize-autoloader --no-progress'))->toBeTrue()
        ->and(str_contains($provisioner, 'sudo_run test -f "${target_dir}/apps/gateway/vendor/autoload.php"'))->toBeTrue()
        ->and(str_contains($provisioner, 'sudo_run test -f "${target_dir}/apps/cli/vendor/autoload.php"'))->toBeTrue()
        ->and(str_contains($provisioner, 'if uses_binary_only_app_artifact; then'))->toBeTrue();
});

it('supports binary-only app node provisioning without a gateway source archive', function (): void {
    $provisioner = file_get_contents(provisionScript());

    expect($provisioner)
        ->toContain('uses_binary_only_app_artifact')
        ->toContain('[ "$NODE_KIND" = "app" ] && [ -n "$BINARY" ]')
        ->toContain('install_orbit_binary_only "$user" "$target_dir"')
        ->toContain('sudo_run install -m 0755 -o "$user" -g "$user" "$BINARY" "$binary_dest"')
        ->toContain('sudo_run ln -sf "$binary_dest" /usr/local/bin/orbit')
        ->toContain('if ! uses_binary_only_app_artifact; then')
        ->toContain('[ -n "$SOURCE_ARCHIVE" ] || fail validation_failed "--source-archive is required"');
});

it('keeps the E2E dependency helper off the SQLite CLI', function (): void {
    $basePackages = Process::run([depsScript(), '--base']);
    $phpPackages = Process::run([depsScript(), '--php']);

    expect($basePackages->successful())->toBeTrue()
        ->and($basePackages->output())->not->toMatch('/^sqlite3$/m')
        ->and($phpPackages->successful())->toBeTrue()
        ->and($phpPackages->output())->toContain('php8.5-sqlite3');
});

it('copies staged Docker image archives into user readable guest var tmp files before install', function (): void {
    $provisioner = file_get_contents(provisionScript());

    expect($provisioner)
        ->toContain('guest_stage_dir="/var/tmp/orbit-e2e-install"')
        ->toContain('sudo_run install -d -m 0755 -o "$user" -g "$user" "$guest_stage_dir"')
        ->toContain('staged_source_archive="${guest_stage_dir}/orbit-source.tar.gz"')
        ->toContain('staged_installer="${guest_stage_dir}/install-orbit"')
        ->toContain('sudo_run install -m 0644 -o "$user" -g "$user" "$GATEWAY_IMAGE_ARCHIVE" "$staged_gateway_image_archive"')
        ->toContain('gateway_image_args=(--gateway-image="$GATEWAY_IMAGE" --gateway-image-archive="$staged_gateway_image_archive")')
        ->toContain('sudo_run install -m 0644 -o "$user" -g "$user" "$CADDY_IMAGE_ARCHIVE" "$staged_caddy_image_archive"')
        ->toContain('caddy_image_args=(--caddy-image-archive="$staged_caddy_image_archive")')
        ->toContain('sudo_run install -m 0644 -o "$user" -g "$user" "$DNSMASQ_IMAGE_ARCHIVE" "$staged_dnsmasq_image_archive"')
        ->toContain('dnsmasq_image_args=(--dnsmasq-image-archive="$staged_dnsmasq_image_archive")')
        ->toContain('sudo_run install -m 0644 -o "$user" -g "$user" "$FRANKENPHP_IMAGE_ARCHIVE" "$staged_frankenphp_image_archive"')
        ->toContain('frankenphp_image_args=(--frankenphp-image-archive="$staged_frankenphp_image_archive")')
        ->toContain('sudo_run install -m 0644 -o "$user" -g "$user" "$WG_EASY_IMAGE_ARCHIVE" "$staged_wg_easy_image_archive"')
        ->toContain('wg_easy_image_args=(--wg-easy-image-archive="$staged_wg_easy_image_archive")')
        ->toContain('${guest_stage_dir}/orbit-gateway-current.tar')
        ->toContain('${guest_stage_dir}/caddy-2-alpine.tar')
        ->toContain('${guest_stage_dir}/dnsmasq-latest.tar')
        ->toContain('${guest_stage_dir}/frankenphp-1-php8.5-bookworm.tar')
        ->toContain('${guest_stage_dir}/wg-easy-15.tar')
        ->toContain('sudo_run rm -rf /var/tmp/orbit-e2e-install');
});

it('fails when gateway image archive does not exist', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-provision-source-');

    try {
        $result = Process::run([
            provisionScript(),
            '--node-kind=gateway',
            "--source-archive={$source}",
            '--gateway-image-archive=/tmp/orbit-gateway-does-not-exist.tar',
        ]);

        expect($result->successful())->toBeFalse();
        expect($result->errorOutput())->toContain('gateway image archive not found');
    } finally {
        @unlink($source);
    }
});

it('fails when --gateway-image is empty', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-provision-source-');

    try {
        $result = Process::run([
            provisionScript(),
            '--node-kind=gateway',
            "--source-archive={$source}",
            '--gateway-image=',
        ]);

        expect($result->successful())->toBeFalse();
        expect($result->errorOutput())->toContain('--gateway-image must not be empty');
    } finally {
        @unlink($source);
    }
});

it('fails when --node-kind is missing', function (): void {
    $result = Process::run([provisionScript()]);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('--node-kind is required');
});

it('fails when --node-kind is invalid', function (): void {
    $result = Process::run([provisionScript(), '--node-kind=invalid', '--source-archive=/tmp/missing']);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('--node-kind must be: operator, gateway, or app');
});

it('rejects retired role-shaped provisioner input', function (): void {
    $roleFlag = '--role=operator';
    $controlKindFlag = '--node-kind=control';

    $roleResult = Process::run([provisionScript(), $roleFlag, '--source-archive=/tmp/missing']);
    $controlResult = Process::run([provisionScript(), $controlKindFlag, '--source-archive=/tmp/missing']);

    expect($roleResult->successful())->toBeFalse()
        ->and($roleResult->errorOutput())->toContain("unknown option: {$roleFlag}")
        ->and($controlResult->successful())->toBeFalse()
        ->and($controlResult->errorOutput())->toContain('--node-kind must be: operator, gateway, or app');
});

it('fails when --source-archive is missing', function (): void {
    $result = Process::run([provisionScript(), '--node-kind=operator']);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('--source-archive is required');
});

it('fails when source archive does not exist', function (): void {
    $result = Process::run([provisionScript(), '--node-kind=operator', '--source-archive=/tmp/orbit-does-not-exist.tar.gz']);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('source archive not found');
});

it('fails when caddy image archive does not exist', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-provision-source-');

    try {
        $result = Process::run([
            provisionScript(),
            '--node-kind=operator',
            "--source-archive={$source}",
            '--caddy-image-archive=/tmp/orbit-caddy-does-not-exist.tar',
        ]);

        expect($result->successful())->toBeFalse();
        expect($result->errorOutput())->toContain('caddy image archive not found');
    } finally {
        @unlink($source);
    }
});

it('rejects the retired runtime image archive option', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-provision-source-');
    $retiredOption = '--runtime'.'-image-archive=/tmp/orbit-gateway-does-not-exist.tar';

    try {
        $result = Process::run([
            provisionScript(),
            '--node-kind=operator',
            "--source-archive={$source}",
            $retiredOption,
        ]);

        expect($result->successful())->toBeFalse();
        expect($result->errorOutput())->toContain("unknown option: {$retiredOption}");
    } finally {
        @unlink($source);
    }
});

it('fails when dnsmasq image archive does not exist', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-provision-source-');

    try {
        $result = Process::run([
            provisionScript(),
            '--node-kind=operator',
            "--source-archive={$source}",
            '--dnsmasq-image-archive=/tmp/orbit-dnsmasq-does-not-exist.tar',
        ]);

        expect($result->successful())->toBeFalse();
        expect($result->errorOutput())->toContain('dnsmasq image archive not found');
    } finally {
        @unlink($source);
    }
});

it('fails when frankenphp image archive does not exist', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-provision-source-');

    try {
        $result = Process::run([
            provisionScript(),
            '--node-kind=operator',
            "--source-archive={$source}",
            '--frankenphp-image-archive=/tmp/orbit-frankenphp-does-not-exist.tar',
        ]);

        expect($result->successful())->toBeFalse();
        expect($result->errorOutput())->toContain('frankenphp image archive not found');
    } finally {
        @unlink($source);
    }
});

it('fails when wg-easy image archive does not exist', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-provision-source-');

    try {
        $result = Process::run([
            provisionScript(),
            '--node-kind=gateway',
            "--source-archive={$source}",
            '--wg-easy-image-archive=/tmp/orbit-wg-easy-does-not-exist.tar',
        ]);

        expect($result->successful())->toBeFalse();
        expect($result->errorOutput())->toContain('wg-easy image archive not found');
    } finally {
        @unlink($source);
    }
});

it('fails for unknown options', function (): void {
    $result = Process::run([provisionScript(), '--node-kind=operator', '--source-archive=/tmp/x', '--mystery=1']);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('unknown option: --mystery=1');
});

it('deps helper prints all packages by default', function (): void {
    $result = Process::run([depsScript()]);

    expect($result->successful())->toBeTrue();

    $lines = array_filter(array_map('trim', explode("\n", $result->output())));

    expect($lines)->toContain('bind9-dnsutils', 'ca-certificates', 'composer', 'git', 'ufw', 'wireguard');
    expect($lines)->not->toContain('supervisor');
    expect($lines)->toContain('php8.5-cli', 'php8.5-bcmath', 'php8.5-zip');
});

it('deps helper prints only base packages with --base', function (): void {
    $result = Process::run([depsScript(), '--base']);

    expect($result->successful())->toBeTrue();

    $lines = array_filter(array_map('trim', explode("\n", $result->output())));

    expect($lines)->toContain('ca-certificates');
    expect($lines)->not->toContain('php8.5-cli');
});

it('deps helper prints only php packages with --php', function (): void {
    $result = Process::run([depsScript(), '--php']);

    expect($result->successful())->toBeTrue();

    $lines = array_filter(array_map('trim', explode("\n", $result->output())));

    expect($lines)->toContain('php8.5-cli');
    expect($lines)->not->toContain('ca-certificates');
});

it('deps helper rejects unknown selectors', function (): void {
    $result = Process::run([depsScript(), '--mystery']);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('usage:');
});
