<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Contracts\RemoteShell;
use App\Models\Node;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class WebSocketRuntimeSourceInstaller
{
    public const RuntimeRoot = '/opt/orbit/websocket';

    public const AppsConfigPath = '/etc/orbit/websocket/apps.php';

    private readonly string $sourcePath;

    public function __construct(
        private readonly RemoteShell $remoteShell,
        private readonly ?WebSocketRoleBaselineTiming $timing = null,
        ?string $sourcePath = null,
    ) {
        $this->sourcePath = rtrim($sourcePath ?? repo_path('apps/reverb'), DIRECTORY_SEPARATOR);
    }

    public function install(Node $node): void
    {
        $files = $this->timer()->measure('source-files', fn (): array => $this->sourceFiles());
        $sourceHash = $this->timer()->measure('source-hash', fn (): string => $this->sourceHash($files));
        $sourceArchive = $this->timer()->measure('source-archive', fn (): string => $this->sourceArchive($files));

        $result = $this->timer()->measure(
            'source-remote',
            fn () => $this->remoteShell->run($node, $this->installScript($sourceHash), [
                'throw' => true,
                'input' => base64_encode((string) $sourceArchive),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'websocket-runtime-source-install',
                ],
            ]),
        );

        $this->recordRemoteTimings($result->stdout);
    }

    private function installScript(string $sourceHash): string
    {
        return sprintf(
            <<<'SH'
set -e
now_ms() { if command -v python3 >/dev/null 2>&1; then python3 -c 'import time; print(int(time.time() * 1000))'; else echo "$(($(date +%%s) * 1000))"; fi; }
record_timing() { printf '__orbit_websocket_source_timing %%s %%s\n' "$1" "$2"; }
runtime_root=%s
release_dir="${runtime_root}/releases/%s"
shared_dir="${runtime_root}/shared"
shared_env="${shared_dir}/.env"
apps_config=%s
expected_hash=%s
source_archive="$(mktemp)"
cleanup() {
    rm -f "$source_archive"
}
trap cleanup EXIT

cat > "$source_archive"

step_start="$(now_ms)"
sudo install -d -m 0755 "$runtime_root" "${runtime_root}/releases" "$shared_dir" "$(dirname "$apps_config")"

if ! sudo test -f "$apps_config"; then
    printf '%%s\n' '<?php return [];' | sudo tee "$apps_config" >/dev/null
    sudo chmod 0644 "$apps_config"
fi
record_timing setup "$(($(now_ms) - step_start))"

current_hash="$(sudo cat "${release_dir}/.orbit-websocket-source-hash" 2>/dev/null || true)"

step_start="$(now_ms)"
if [ "$current_hash" != "$expected_hash" ]; then
    sudo rm -rf "$release_dir"
    sudo install -d -m 0755 "$release_dir"
    base64 -d "$source_archive" | sudo tar -xf - -C "$release_dir"
    sudo find "$release_dir" -type d -exec chmod 0755 {} +
    sudo find "$release_dir" -type f -exec chmod 0644 {} +
    sudo chmod 0755 "${release_dir}/artisan"
fi
record_timing extract "$(($(now_ms) - step_start))"

step_start="$(now_ms)"
if ! sudo test -f "$shared_env"; then
    app_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    printf 'APP_KEY=%%s\n' "$app_key" | sudo tee "$shared_env" >/dev/null
    sudo chmod 0600 "$shared_env"
elif ! sudo grep -q '^APP_KEY=' "$shared_env"; then
    app_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    printf 'APP_KEY=%%s\n' "$app_key" | sudo tee -a "$shared_env" >/dev/null
fi

sudo ln -sfn "$shared_env" "${release_dir}/.env"
record_timing env "$(($(now_ms) - step_start))"

step_start="$(now_ms)"
if ! sudo test -f "${release_dir}/vendor/autoload.php"; then
    if ! command -v composer >/dev/null 2>&1; then
        printf 'WebSocket runtime dependencies require host composer.\n' >&2
        exit 1
    fi

    cd "$release_dir"
    sudo env COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
fi
record_timing composer "$(($(now_ms) - step_start))"

step_start="$(now_ms)"
printf '%%s\n' "$expected_hash" | sudo tee "${release_dir}/.orbit-websocket-source-hash" >/dev/null
sudo ln -sfn "releases/${expected_hash}" %s
record_timing activate "$(($(now_ms) - step_start))"
SH,
            escapeshellarg(self::RuntimeRoot),
            $sourceHash,
            escapeshellarg(self::AppsConfigPath),
            escapeshellarg($sourceHash),
            escapeshellarg(WebSocketRuntimeContainer::SourceHostPath),
        );
    }

    private function normalizeSourcePath(string $sourcePath): string
    {
        $sourcePath = rtrim($sourcePath, DIRECTORY_SEPARATOR);

        if ($sourcePath === '' || ! is_dir($sourcePath)) {
            throw new InvalidArgumentException("WebSocket runtime source path [{$sourcePath}] does not exist.");
        }

        return $sourcePath;
    }

    /**
     * @return list<array{path: string, contents: string, executable: bool}>
     */
    private function sourceFiles(): array
    {
        $files = [];
        $sourcePath = $this->normalizeSourcePath($this->sourcePath);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $relativePath = $this->relativePath($file);

            if ($this->shouldSkip($relativePath)) {
                continue;
            }

            $files[] = [
                'path' => $relativePath,
                'contents' => File::get($file->getPathname()),
                'executable' => $relativePath === 'artisan',
            ];
        }

        usort($files, fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        $this->assertRequiredFiles($files);

        return $files;
    }

    private function relativePath(SplFileInfo $file): string
    {
        $sourcePath = $this->normalizeSourcePath($this->sourcePath);
        $relativePath = substr($file->getPathname(), strlen($sourcePath) + 1);

        return str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
    }

    private function shouldSkip(string $relativePath): bool
    {
        return $relativePath === '.env'
            || $relativePath === 'vendor'
            || str_starts_with($relativePath, 'vendor/')
            || preg_match('#^bootstrap/cache/(?!\.gitignore$)#', $relativePath) === 1;
    }

    /**
     * @param  list<array{path: string, contents: string, executable: bool}>  $files
     */
    private function assertRequiredFiles(array $files): void
    {
        $paths = array_column($files, 'path');

        foreach (['artisan', 'bootstrap/app.php', 'composer.json', 'composer.lock', 'config/reverb.php'] as $requiredPath) {
            if (! in_array($requiredPath, $paths, true)) {
                throw new RuntimeException("WebSocket runtime source is missing [{$requiredPath}].");
            }
        }
    }

    /**
     * @param  list<array{path: string, contents: string, executable: bool}>  $files
     */
    private function sourceHash(array $files): string
    {
        $context = hash_init('sha256');

        foreach ($files as $file) {
            hash_update($context, $file['path']."\0".hash('sha256', $file['contents'])."\0");
        }

        return hash_final($context);
    }

    /**
     * @param  list<array{path: string, contents: string, executable: bool}>  $files
     */
    private function sourceArchive(array $files): string
    {
        $basePath = tempnam(sys_get_temp_dir(), 'orbit-websocket-source-');

        if ($basePath === false) {
            throw new RuntimeException('Could not create a temporary WebSocket runtime source archive path.');
        }

        $tarPath = "{$basePath}.tar";
        @unlink($basePath);

        try {
            $archive = new PharData($tarPath);

            foreach ($files as $file) {
                $archive->addFromString($file['path'], $file['contents']);
            }

            $contents = file_get_contents($tarPath);

            if ($contents === false) {
                throw new RuntimeException('Could not read the WebSocket runtime source archive.');
            }

            return $contents;
        } finally {
            if (is_file($tarPath)) {
                @unlink($tarPath);
            }
        }
    }

    private function recordRemoteTimings(string $output): void
    {
        if (preg_match_all('/__orbit_websocket_source_timing\s+([a-z-]+)\s+(\d+)/', $output, $matches, PREG_SET_ORDER) === false) {
            return;
        }

        foreach ($matches as $match) {
            $this->timer()->record("source-{$match[1]}", (int) $match[2]);
        }
    }

    private function timer(): WebSocketRoleBaselineTiming
    {
        return $this->timing ?? app(WebSocketRoleBaselineTiming::class);
    }
}
