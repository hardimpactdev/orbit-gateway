<?php

declare(strict_types=1);

use App\E2E\Support\DockerTopologyBuilder;
use App\E2E\Support\DockerTopologyProvider;
use Symfony\Component\Process\Process;

pest()->group('e2e', 'e2e-docker-image-contract');

it('keeps a gateway image build context that excludes host secrets and local artifacts', function (): void {
    $ignore = file_get_contents(repo_path('docker/orbit-gateway/Dockerfile.dockerignore'));

    expect(file_exists(repo_path('docker/orbit-gateway/Dockerfile')))->toBeTrue()
        ->and(file_exists(repo_path('docker/orbit-gateway/Dockerfile.dockerignore')))->toBeTrue()
        ->and(file_exists(repo_path('docker/orbit-gateway/entrypoint.sh')))->toBeTrue()
        ->and($ignore)->toContain('**/.env')
        ->and($ignore)->toContain('**/vendor')
        ->and($ignore)->toContain('**/node_modules')
        ->and($ignore)->toContain('**/tests')
        ->and($ignore)->toContain('**/build')
        ->and($ignore)->toContain('**/builds')
        ->and($ignore)->toContain('!apps/gateway/app/**')
        ->and($ignore)->toContain('!apps/gateway/resources/views/**')
        ->and($ignore)->toContain('!packages/core/src/**')
        ->and($ignore)->not->toContain('!apps/gateway/**')
        ->and($ignore)->not->toContain('!apps/reverb/**')
        ->and($ignore)->not->toContain('!packages/core/**')
        ->and($ignore)->toContain('.git')
        ->and($ignore)->toContain('apps/gateway/database/*.sqlite')
        ->and($ignore)->toContain('apps/gateway/storage/*.sqlite')
        ->and($ignore)->toContain('apps/gateway/storage/logs')
        ->and($ignore)->toContain('apps/gateway/storage/framework')
        ->and($ignore)->toContain('apps/gateway/storage/app/orbit/ca')
        ->and($ignore)->toContain('apps/gateway/storage/app/orbit/certs')
        ->and($ignore)->toContain('apps/gateway/storage/app/orbit/keys');
});

it('keeps a self-contained Reverb image build context separate from gateway', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/orbit-reverb/Dockerfile'));
    $ignore = file_get_contents(repo_path('docker/orbit-reverb/Dockerfile.dockerignore'));

    expect(file_exists(repo_path('docker/orbit-reverb/Dockerfile')))->toBeTrue()
        ->and(file_exists(repo_path('docker/orbit-reverb/Dockerfile.dockerignore')))->toBeTrue()
        ->and($dockerfile)->toContain('COPY apps/reverb /app')
        ->and($dockerfile)->toContain('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts')
        ->and($dockerfile)->toContain('LABEL orbit.websocket.self_contained="true"')
        ->and($ignore)->toContain('!apps/reverb/**')
        ->and($ignore)->toContain('**/vendor')
        ->and($ignore)->not->toContain('!apps/gateway/**');
});

it('uses the orbit-gateway image for source-dev gateway sibling execution', function (): void {
    expect(DockerTopologyProvider::gatewaySiblingImage())
        ->toBe(DockerTopologyProvider::gatewayImage())
        ->toBe('orbit-gateway:prepared-current');
});

it('keeps the gateway image capable of mounted source commands for source-dev topology helpers', function (): void {
    $dockerfile = file_get_contents(repo_path('docker/orbit-gateway/Dockerfile'));
    $entrypoint = file_get_contents(repo_path('docker/orbit-gateway/entrypoint.sh'));

    expect($dockerfile)
        ->toContain('getcomposer.org/download/latest-stable/composer.phar')
        ->toContain('composer install --no-dev --no-interaction --prefer-dist --no-autoloader --no-scripts')
        ->toContain('composer dump-autoload --no-dev --no-interaction --optimize --no-scripts')
        ->toContain('COPY packages/core/src /srv/orbit/packages/core/src')
        ->toContain('download.docker.com/linux/debian')
        ->toContain('docker-ce-cli')
        ->toContain('docker-compose-plugin')
        ->toContain('git')
        ->toContain('openssh-client')
        ->toContain('procps')
        ->not->toContain('COPY --from=composer')
        ->not->toContain('COPY --from=docker')
        ->and($entrypoint)
        ->toContain('ORBIT_GATEWAY_APP_ROOT:-/srv/orbit/apps/gateway')
        ->toContain('run_artisan "$@"');
});

it('loads gateway app classes ahead of stale root Composer autoload mappings', function (): void {
    $root = sys_get_temp_dir().'/orbit-gateway-artisan-autoload-'.bin2hex(random_bytes(6));
    $gateway = "{$root}/apps/gateway";

    mkdir("{$gateway}/app/Console", recursive: true);
    mkdir("{$gateway}/bootstrap", recursive: true);
    mkdir("{$gateway}/symfony/Input", recursive: true);
    mkdir("{$gateway}/vendor", recursive: true);
    mkdir("{$root}/stale/app/Console", recursive: true);
    mkdir("{$root}/vendor", recursive: true);

    file_put_contents("{$gateway}/artisan", file_get_contents(repo_path('apps/gateway/artisan')));
    chmod("{$gateway}/artisan", 0755);

    file_put_contents("{$gateway}/bootstrap/app.php", <<<'PHP'
<?php

return new App\Console\Kernel;
PHP);

    file_put_contents("{$gateway}/app/Console/Kernel.php", <<<'PHP'
<?php

namespace App\Console;

class Kernel
{
    public function handleCommand(object $input): int
    {
        echo 'gateway';

        return 0;
    }
}
PHP);

    file_put_contents("{$root}/stale/app/Console/Kernel.php", <<<'PHP'
<?php

namespace App\Console;

class Kernel
{
    public function handleCommand(object $input): int
    {
        echo 'stale';

        return 0;
    }
}
PHP);

    file_put_contents("{$gateway}/symfony/Input/ArgvInput.php", <<<'PHP'
<?php

namespace Symfony\Component\Console\Input;

class ArgvInput {}
PHP);

    file_put_contents("{$gateway}/vendor/autoload.php", <<<'PHP'
<?php

spl_autoload_register(static function (string $class): void {
    if ($class === 'Symfony\\Component\\Console\\Input\\ArgvInput') {
        require __DIR__.'/../symfony/Input/ArgvInput.php';

        return;
    }

    if ($class === 'App\\Console\\Kernel') {
        require __DIR__.'/../app/Console/Kernel.php';
    }
}, prepend: true);
PHP);

    file_put_contents("{$root}/vendor/autoload.php", <<<'PHP'
<?php

spl_autoload_register(static function (string $class): void {
    if ($class === 'Symfony\\Component\\Console\\Input\\ArgvInput') {
        require __DIR__.'/../apps/gateway/symfony/Input/ArgvInput.php';

        return;
    }

    if ($class === 'App\\Console\\Kernel') {
        require __DIR__.'/../stale/app/Console/Kernel.php';
    }
}, prepend: true);
PHP);

    try {
        $process = new Process(['php', "{$gateway}/artisan"]);
        $process->run();

        expect($process->getExitCode())
            ->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())
            ->toBe('gateway');
    } finally {
        (new Process(['rm', '-rf', $root]))->run();
    }
});

it('passes non-orbit entrypoint commands through unchanged', function (): void {
    $process = new Process([
        'bash',
        repo_path('docker/orbit-gateway/entrypoint.sh'),
        'printf',
        'ok',
    ]);

    $process->run();

    expect($process->getExitCode())
        ->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())
        ->toBe('ok');
});

it('does not ship persisted orbit certificate material in the runtime image', function (): void {
    $image = DockerTopologyBuilder::runtimeImage();

    $availability = new Process([
        'docker',
        'image',
        'inspect',
        $image,
    ]);

    $availability->run();

    if ($availability->getExitCode() !== 0) {
        test()->markTestSkipped("Docker runtime image {$image} is not available.");
    }

    $forbiddenPaths = [
        '/opt/orbit-source/apps/gateway/storage/app/orbit/ca',
        '/opt/orbit-source/apps/gateway/storage/app/orbit/certs',
        '/opt/orbit-source/apps/gateway/storage/app/orbit/keys',
        '/home/operator/orbit/apps/gateway/storage/app/orbit/ca',
        '/home/operator/orbit/apps/gateway/storage/app/orbit/certs',
        '/home/operator/orbit/apps/gateway/storage/app/orbit/keys',
        '/home/orbit/orbit/apps/gateway/storage/app/orbit/ca',
        '/home/orbit/orbit/apps/gateway/storage/app/orbit/certs',
        '/home/orbit/orbit/apps/gateway/storage/app/orbit/keys',
    ];

    $assertions = collect($forbiddenPaths)
        ->map(fn (string $path): string => sprintf('test ! -e %s || { echo "FORBIDDEN PATH PRESENT: %s"; exit 1; }', escapeshellarg($path), $path))
        ->implode('; ');

    $process = new Process([
        'docker',
        'run',
        '--rm',
        $image,
        'bash',
        '-c',
        sprintf('set -e; %s; echo OK', $assertions),
    ]);

    $process->run();

    expect($process->getExitCode())
        ->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())
        ->toContain('OK');
});

it('provides Docker CLI and host PHP CLI baseline without ad hoc helper tools in the topology image', function (): void {
    $image = DockerTopologyBuilder::runtimeImage();

    $availability = new Process([
        'docker',
        'image',
        'inspect',
        $image,
    ]);

    $availability->run();

    if ($availability->getExitCode() !== 0) {
        test()->markTestSkipped("Docker runtime image {$image} is not available.");
    }

    $label = new Process([
        'docker',
        'image',
        'inspect',
        '--format',
        '{{ index .Config.Labels "org.orbit.e2e.substrate" }}',
        $image,
    ]);

    $label->run();

    if (trim($label->getOutput()) !== 'docker-first') {
        test()->markTestSkipped("Docker runtime image {$image} was not built from the Docker-first topology Dockerfile.");
    }

    $sourceLabel = new Process([
        'docker',
        'image',
        'inspect',
        '--format',
        '{{ index .Config.Labels "org.orbit.e2e.source" }}',
        $image,
    ]);

    $sourceLabel->run();

    if (trim($sourceLabel->getOutput()) !== 'prepared-checkout') {
        test()->markTestSkipped("Docker runtime image {$image} was not built from the source-less topology Dockerfile.");
    }

    $process = new Process([
        'docker',
        'run',
        '--rm',
        $image,
        'bash',
        '-c',
        implode(' && ', [
            'command -v docker',
            'command -v php',
            'php --version | grep -q "^PHP 8[.]5[.]"',
            'php -r \'foreach (["pdo_sqlite", "openssl", "curl", "mbstring", "json", "xml"] as $extension) { if (! extension_loaded($extension)) { fwrite(STDERR, $extension.PHP_EOL); exit(1); } }\'',
            '! command -v python3',
            '! command -v sqlite3',
            '! command -v composer',
            '! command -v caddy',
            '! command -v php-fpm',
            '! systemctl status caddy >/tmp/orbit-caddy-status.log 2>&1',
            '! command -v orbit',
            '! test -e /opt/orbit-source',
            '! test -f /home/operator/orbit/artisan',
            '! test -f /home/operator/orbit/apps/gateway/artisan',
            '! test -f /home/orbit/orbit/artisan',
            '! test -f /home/orbit/orbit/apps/gateway/artisan',
            'echo OK',
        ]),
    ]);

    $process->run();

    expect($process->getExitCode())
        ->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())
        ->toContain('OK');
});
