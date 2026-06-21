<?php

declare(strict_types=1);

it('keeps root gateway forwarding helper scripts available', function (): void {
    expect(repo_path('bin/orbit-gateway-artisan'))->toBeFile()
        ->and(repo_path('bin/orbit-gateway-pest'))->toBeFile()
        ->and(repo_path('bin/orbit-gateway-vendor-bin'))->toBeFile()
        ->and(repo_path('bin/orbit-cli-artisan'))->toBeFile()
        ->and(repo_path('bin/orbit-cli-pest'))->toBeFile()
        ->and(repo_path('artisan'))->not->toBeFile()
        ->and(repo_path('phpunit.xml'))->not->toBeFile();
});

it('routes root composer scripts through app-aware helpers', function (): void {
    $composer = json_decode(
        (string) file_get_contents(repo_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['scripts']['docs-lint'])
        ->each->toContain('bin/orbit-docs-artisan')
        ->and($composer['scripts']['test'][1])->toContain('bin/orbit-gateway-artisan config:clear')
        ->and($composer['scripts']['test'][2])->toContain('bin/orbit-gateway-pest')
        ->and($composer['scripts']['test'][3])->toContain('bin/orbit-cli-pest')
        ->and($composer['scripts']['test'][4])->toContain('bin/orbit-docs-pest')
        ->and($composer['scripts']['test'][5])->toContain('cd packages/core && vendor/bin/pest')
        ->and($composer['scripts']['test:slow'][1])->toContain('bin/orbit-gateway-artisan config:clear')
        ->and($composer['scripts']['test:slow'][2])->toContain('bin/orbit-gateway-pest')
        ->and($composer['scripts']['test:e2e'][1])->toContain('bin/orbit-e2e-artisan e2e:test')
        ->and($composer['scripts']['test:e2e:docker'][1])->toContain('bin/orbit-e2e-artisan e2e:test')
        ->and($composer['scripts']['test:e2e:docker:canary'][1])->toContain('bin/orbit-e2e-artisan e2e:test --canary')
        ->and($composer['scripts']['test:e2e:incus'][1])->toContain('bin/orbit-e2e-artisan e2e:test')
        ->and($composer['scripts']['test:e2e:provision'][1])->toContain('composer test:e2e:provision:docker')
        ->and($composer['scripts']['test:e2e:provision'][2])->toContain('composer test:e2e:provision:incus')
        ->and($composer['scripts']['test:e2e:provision:docker'][1])->toContain('bin/orbit-e2e-artisan e2e:prepare-docker-hosts')
        ->and($composer['scripts']['test:e2e:provision:docker'][1])->toContain('--rebuild')
        ->and($composer['scripts']['test:e2e:provision:incus'][1])->toContain('cd apps/e2e')
        ->and($composer['scripts']['e2e:preflight'])->toContain('bin/orbit-e2e-artisan e2e:preflight')
        ->and($composer['scripts']['e2e:prepare-docker-topology'][1])->toContain('bin/orbit-e2e-artisan e2e:prepare-docker-topology')
        ->and($composer['scripts']['analyse'])->toContain('bin/orbit-gateway-vendor-bin phpstan analyse --memory-limit=512M')
        ->and($composer['scripts']['analyse'])->toContain('cd apps/cli && vendor/bin/phpstan analyse --memory-limit=512M')
        ->and($composer['scripts']['analyse'])->toContain('cd apps/docs && vendor/bin/phpstan analyse --memory-limit=512M')
        ->and($composer['scripts']['analyse'])->toContain('cd packages/core && vendor/bin/phpstan analyse --memory-limit=512M')
        ->and($composer['scripts']['format'][0])->toContain('bin/orbit-gateway-vendor-bin pint')
        ->and($composer['scripts']['format'])->toContain('cd apps/cli && vendor/bin/pint')
        ->and($composer['scripts']['format'])->toContain('cd apps/docs && vendor/bin/pint')
        ->and($composer['scripts']['format'])->toContain('cd packages/core && vendor/bin/pint')
        ->and($composer['scripts']['rector'])->toContain('bin/orbit-gateway-vendor-bin rector process')
        ->and($composer['scripts']['rector'])->toContain('cd apps/cli && vendor/bin/rector process')
        ->and($composer['scripts']['rector'])->toContain('cd apps/docs && vendor/bin/rector process')
        ->and($composer['scripts']['rector'])->toContain('cd packages/core && vendor/bin/rector process')
        ->and($composer)->not->toHaveKeys(['autoload', 'autoload-dev']);
});

it('keeps public orbit launcher pointed at the cli app only', function (): void {
    $launcher = file_get_contents(repo_path('bin/orbit')) ?: '';

    expect($launcher)
        ->toContain('resolve_default_repo')
        ->toContain('repo_root="$(resolve_default_repo)"')
        ->toContain('exec "${repo_root}/apps/cli/orbit" "$@"')
        ->toContain('ORBIT_APP="cli"')
        ->not->toContain('apps/gateway/.env')
        ->not->toContain('ORBIT_REPO')
        ->not->toContain('is_local_executor_command')
        ->not->toContain('${repo_root}/apps/gateway/artisan')
        ->not->toContain('${repo_root}/artisan')
        ->not->toContain('ORBIT_APP="gateway"')
        ->not->toContain('gateway_flag');
});
