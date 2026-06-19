<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

describe('install-orbit always-cli launcher contract', function (): void {
    it('keeps the installed host command pointed at the downloaded Orbit CLI binary', function (): void {
        $installer = File::get(repo_path('bin/install-orbit'));

        expect($installer)
            ->toContain('ln -sf "$TARGET_DIR/bin/orbit-binary" "$LINK_PATH"')
            ->not->toContain('ln -sf "$TARGET_DIR/apps/cli/orbit" "$LINK_PATH"')
            ->not->toContain('ln -sf "$TARGET_DIR/artisan" "$LINK_PATH"');
    });

    it('writes a per-operator-host CLI config skeleton during install (D11 + D13)', function (): void {
        $installer = File::get(repo_path('bin/install-orbit'));

        expect($installer)
            ->toContain('write_cli_config_skeleton')
            ->toContain('.config/orbit/config.json')
            ->toContain('-m 0700')
            ->toContain('-m 0600')
            ->toContain('"schema_version": 1');
    });

    it('writes per-operator-host CLI install metadata after the binary verifies', function (): void {
        $installer = File::get(repo_path('bin/install-orbit'));

        expect($installer)
            ->toContain('write_install_metadata')
            ->toContain('ORBIT_INSTALL_METADATA_PATH')
            ->toContain('.config/orbit/install.json')
            ->toContain('"installed_at": "$(json_escape "$installed_at")"')
            ->toContain('"binary_path": "$(json_escape "$LINK_PATH")"')
            ->toContain('"install_root": "$(json_escape "$TARGET_DIR")"');

        expect(strrpos($installer, 'verify_install'))->toBeLessThan(strrpos($installer, 'write_install_metadata'));
    });

    it('writes the CLI config skeleton through sudo install so container-owned config roots are repaired', function (): void {
        $installer = File::get(repo_path('bin/install-orbit'));

        expect($installer)
            ->toContain('owner="$(id -un)"')
            ->toContain('group="$(id -gn)"')
            ->toContain('sudo_run install -d -m 0755 -o "$owner" -g "$group" "$config_parent"')
            ->toContain('sudo_run install -d -m 0700 -o "$owner" -g "$group" "$config_dir"')
            ->toContain('tmp_file="$(mktemp "${TMPDIR:-/tmp}/orbit-cli-config.XXXXXX")"')
            ->toContain('sudo_run install -m 0600 -o "$owner" -g "$group" "$tmp_file" "$config_file"')
            ->not->toContain('cat > "$config_file"');
    });

    it('dispatches public commands through the source CLI entrypoint', function (): void {
        $capture = orbitLauncherProbe(arguments: ['node:list', '--json']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['args'])->toBe('[node:list][--json]');
    });

    it('dispatches internal commands through the same source CLI entrypoint', function (): void {
        $capture = orbitLauncherProbe(arguments: ['internal:wg-easy:state', '--json']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['args'])->toBe('[internal:wg-easy:state][--json]');
    });

    it('routes internal commands through the same wrapper path without special handling', function (): void {
        $capture = orbitLauncherProbe(arguments: ['internal:database-query-local', '--json']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['args'])->toBe('[internal:database-query-local][--json]');
    });

    it('defaults unconfigured nodes to the source CLI entrypoint', function (): void {
        $capture = orbitLauncherProbe(arguments: ['node:doctor']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['args'])->toBe('[node:doctor]');
    });

    it('propagates wrapper arguments even when flags are present', function (): void {
        $capture = orbitLauncherProbe(arguments: ['--json', 'node:list', '--no-interaction']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['args'])->toBe('[--json][node:list][--no-interaction]');
    });

    it('resolves the repo root from the wrapper location instead of using a production default', function (): void {
        $launcher = File::get(repo_path('bin/orbit'));

        expect($launcher)
            ->toContain('resolve_default_repo')
            ->toContain('apps/cli/orbit')
            ->not->toContain('/home/orbit/orbit');
    });

    it('fails clearly when the local CLI artifact dependencies are missing', function (): void {
        $cli = File::get(repo_path('apps/cli/orbit'));

        expect($cli)
            ->toContain("__DIR__.'/vendor/autoload.php'")
            ->toContain('Orbit CLI dependencies are not installed')
            ->not->toContain("__DIR__.'/../../autoload.php'");
    });

    it('keeps the CLI artifact free of the removed bridge launcher path', function (): void {
        $launcher = File::get(repo_path('apps/cli/orbit'));

        expect($launcher)
            ->toContain("__DIR__.'/NativeCommandNormalizer.php'")
            ->not->toContain('CompatibilityBridge.php')
            ->not->toContain("dirname(__DIR__, 2).'/apps/gateway/artisan'");

        expect(File::exists(repo_path('apps/cli/CompatibilityBridge.php')))->toBeFalse();
    });

    it('keeps the convenience wrapper free of env-file reads secret bridging and allow-list logic', function (): void {
        $launcher = File::get(repo_path('bin/orbit'));

        expect($launcher)
            ->not->toContain('apps/gateway/.env')
            ->not->toContain('is_local_executor_command');
    });

    it('keeps the CLI config gateway focused', function (): void {
        $config = require repo_path('apps/cli/config/orbit.php');

        expect(array_keys($config))->toBe(['gateway']);
    });

});

/**
 * @param  list<string>  $arguments
 * @return array<string, string>
 */
function orbitLauncherProbe(array $arguments): array
{
    $root = sys_get_temp_dir().'/orbit-launcher-contract-'.bin2hex(random_bytes(4));

    try {
        $home = "{$root}/home/orbit";
        $repo = "{$home}/orbit";
        $hostCwd = "{$root}/caller/project";
        $capturePath = "{$root}/launcher-capture";

        orbitLauncherPrepareFakeCheckout($repo);
        File::ensureDirectoryExists($hostCwd);

        $process = new Process(
            [$repo.'/bin/orbit', ...$arguments],
            $hostCwd,
            ['HOME' => $home, 'ORBIT_LAUNCHER_CAPTURE' => $capturePath],
        );

        $process->run();

        expect($process->getExitCode())->toBe(
            0,
            $process->getErrorOutput().$process->getOutput(),
        );
        expect(File::exists($capturePath))->toBeTrue('expected the launcher to execute a fake Orbit artifact');

        return orbitLauncherReadCapture($capturePath) + [
            'repo' => $repo,
            'host_cwd' => $hostCwd,
        ];
    } finally {
        if (is_dir($root)) {
            File::deleteDirectory($root);
        }
    }
}

function orbitLauncherPrepareFakeCheckout(string $repo): void
{
    File::ensureDirectoryExists("{$repo}/bin");
    File::ensureDirectoryExists("{$repo}/apps/cli");

    File::copy(repo_path('bin/orbit'), "{$repo}/bin/orbit");
    chmod("{$repo}/bin/orbit", 0755);

    orbitLauncherWriteExecutable("{$repo}/apps/cli/orbit", orbitLauncherCaptureScript());
}

function orbitLauncherWriteExecutable(string $path, string $contents): void
{
    File::put($path, $contents);
    chmod($path, 0755);
}

function orbitLauncherCaptureScript(): string
{
    return <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
{
    printf 'target=%s\n' "$0"
    printf 'ORBIT_APP=%s\n' "${ORBIT_APP:-}"
    printf 'args='
    for arg in "$@"; do
        printf '[%s]' "$arg"
    done
    printf '\n'
} > "$ORBIT_LAUNCHER_CAPTURE"
BASH;
}

/**
 * @return array<string, string>
 */
function orbitLauncherReadCapture(string $path): array
{
    $capture = [];

    foreach (explode(PHP_EOL, trim(File::get($path))) as $line) {
        [$key, $value] = explode('=', $line, 2);
        $capture[$key] = $value;
    }

    return $capture;
}
