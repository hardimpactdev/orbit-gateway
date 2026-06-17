<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\RemoteShell;
use App\Data\Apps\AppWorkerReadinessResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;

final readonly class AppWorkerReadiness
{
    /**
     * Tokens the probe script writes to stdout. Each token represents an
     * independent piece of evidence; the service only reports ready when
     * every required token is present.
     */
    public const string InstalledToken = 'octane:installed';

    public const string WorkerFileToken = 'frankenphp-worker-file:present';

    public const string ConfiguredToken = 'frankenphp:configured';

    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    public function assess(App $app): AppWorkerReadinessResult
    {
        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            return AppWorkerReadinessResult::notReady(
                code: 'app.worker_unsupported_runtime',
                message: "Worker mode requires runtime_kind=php; app '{$app->name}' uses '{$app->runtime_kind->value}'.",
                missing: ['runtime_kind=php'],
                meta: ['runtime_kind' => $app->runtime_kind->value],
            );
        }

        $node = $app->node;

        if ($node === null) {
            return AppWorkerReadinessResult::notReady(
                code: 'app.worker_unknown_node',
                message: "App '{$app->name}' has no owning node; cannot validate worker readiness.",
                missing: ['owning_node'],
            );
        }

        $appPath = rtrim((string) $app->path, '/');

        if ($appPath === '') {
            return AppWorkerReadinessResult::notReady(
                code: 'app.worker_missing_path',
                message: "App '{$app->name}' has no source path; cannot validate worker readiness.",
                missing: ['app_path'],
            );
        }

        $workerFileRelative = AppRuntimeContainerRenderer::workerFileRelativeToSource($app);

        $script = $this->probeScript($appPath, $workerFileRelative);
        $result = $this->remoteShell->run($node, $script);
        $stdout = trim($result->stdout);

        $missing = [];

        if (! str_contains($stdout, self::InstalledToken)) {
            $missing[] = 'vendor/laravel/octane';
        }

        if (! str_contains($stdout, self::WorkerFileToken)) {
            $missing[] = $workerFileRelative;
        }

        if (! str_contains($stdout, self::ConfiguredToken)) {
            $missing[] = 'octane.server=frankenphp';
        }

        if ($missing !== []) {
            return AppWorkerReadinessResult::notReady(
                code: 'app.worker_readiness_failed',
                message: "App '{$app->name}' is not ready for worker mode.",
                missing: $missing,
                meta: [
                    'probe_output' => $stdout,
                    'worker_file' => $workerFileRelative,
                ],
            );
        }

        return AppWorkerReadinessResult::ready();
    }

    /**
     * Probe script the gateway runs on the app's owning node.
     *
     * The script checks three independent pieces of installed-runtime evidence:
     *
     * - `vendor/laravel/octane/` exists. Just declaring `laravel/octane` in
     *   composer.json or composer.lock is not enough; the vendor directory
     *   must be present so the runtime can actually load Octane.
     * - The FrankenPHP worker file exists at the path the runtime renderer
     *   will point `FRANKENPHP_CONFIG` at. The path is resolved relative to
     *   the app's configured `document_root`, so an app with
     *   `document_root=web` is checked at `web/frankenphp-worker.php`, not
     *   the legacy hardcoded `public/frankenphp-worker.php`.
     * - `config/octane.php` references `frankenphp` outside of comments.
     *   Line comments (`//`, `#`) and lines that are continuation of a
     *   `/* ... *\/` block comment are stripped before matching, so the
     *   default commented example in `octane.php` does not falsely pass.
     */
    public function probeScript(string $appPath, string $workerFileRelative): string
    {
        $escapedAppPath = escapeshellarg($appPath);
        $escapedWorkerFile = escapeshellarg($workerFileRelative);

        return <<<SCRIPT
set -u
APP_PATH={$escapedAppPath}
WORKER_FILE={$escapedWorkerFile}
if [ -d "\$APP_PATH/vendor/laravel/octane" ]; then
    echo octane:installed
fi
if [ -f "\$APP_PATH/\$WORKER_FILE" ]; then
    echo frankenphp-worker-file:present
fi
if [ -f "\$APP_PATH/config/octane.php" ]; then
    if awk '
        BEGIN { in_block = 0 }
        {
            line = \$0
            if (in_block) {
                if (match(line, /\*\//)) {
                    line = substr(line, RSTART + RLENGTH)
                    in_block = 0
                } else {
                    next
                }
            }
            while (match(line, /\/\*/)) {
                close_pos = index(substr(line, RSTART + 2), "*/")
                if (close_pos > 0) {
                    line = substr(line, 1, RSTART - 1) substr(line, RSTART + 2 + close_pos + 1)
                } else {
                    line = substr(line, 1, RSTART - 1)
                    in_block = 1
                    break
                }
            }
            sub(/\/\/.*/, "", line)
            sub(/#.*/, "", line)
            if (line ~ /["'\'']frankenphp["'\'']/) {
                print line
            }
        }
    ' "\$APP_PATH/config/octane.php" | grep -q .; then
        echo frankenphp:configured
    fi
fi
SCRIPT;
    }
}
