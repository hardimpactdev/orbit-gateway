<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Apps\NodeRuntimeConfigsProbeStatus;
use App\Enums\Apps\NodeRuntimeContainersProbeStatus;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpRuntimeCatalog;

final readonly class AppsProbe
{
    public function __construct(
        private ?RemoteShell $remoteShell = null,
        private ?AppRuntimeUser $appRuntimeUser = null,
        private ?AppRuntimeContainerRenderer $appRuntimeContainerRenderer = null,
        private ?PhpRuntimeCatalog $phpRuntimeCatalog = null,
        private ?AppAgentIdeDefaults $agentIdeDefaults = null,
        private ?NodeRoleAssignments $nodeRoleAssignments = null,
    ) {}

    public function key(): string
    {
        return 'app';
    }

    public function label(): string
    {
        return 'Apps';
    }

    public function introspect(App $app): ProbeSnapshot
    {
        $app->loadMissing('node');

        if (! $app->node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        $isPhpApp = $app->runtime_kind === AppRuntimeKind::Php;
        $containerName = $isPhpApp ? $this->appRuntimeContainerRenderer()->containerName($app) : '';
        $expectedSpecHash = '';
        $expectedRuntimeConfigHash = '';
        $runtimeConfigPath = '';
        $expectedImage = '';

        if ($isPhpApp) {
            try {
                $renderedContainer = $this->appRuntimeContainerRenderer()->render($app);
                $expectedSpecHash = $renderedContainer->specHash();
                $expectedRuntimeConfigHash = hash('sha256', $renderedContainer->phpIniContent());
                $runtimeConfigPath = "/etc/orbit/apps/{$app->name}.ini";
                $expectedImage = $renderedContainer->image();
            } catch (\Throwable) {
                $expectedSpecHash = '';
                $expectedRuntimeConfigHash = '';
                $runtimeConfigPath = '';
                $expectedImage = '';
            }
        }

        $script = $this->renderIntrospectScript([
            'APP_NAME' => $app->name,
            'APP_PATH' => rtrim((string) $app->path, '/'),
            'APP_DOCUMENT_ROOT' => (string) $app->document_root,
            'RUNTIME_KIND' => $app->runtime_kind->value,
            'RUNTIME_USER' => $this->appRuntimeUser()->forApp($app),
            'RUNTIME_CONTAINER_NAME' => $containerName,
            'EXPECTED_SPEC_HASH' => $expectedSpecHash,
            'RUNTIME_CONFIG_PATH' => $runtimeConfigPath,
            'EXPECTED_RUNTIME_CONFIG_HASH' => $expectedRuntimeConfigHash,
            'EXPECTED_RUNTIME_IMAGE' => $expectedImage,
        ]);

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($app->node, $script, [
            'throw' => true,
        ]);

        $items = [];

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line);

            if (count($parts) !== 14) {
                continue;
            }

            [
                $name,
                $pathExists,
                $rootExists,
                $rootInsidePath,
                $dockerAvailable,
                $containerExists,
                $containerSpecMatches,
                $containerRunning,
                $systemUserExists,
                $fsPermissionsOk,
                $runtimeConfigExists,
                $runtimeConfigMatches,
                $runtimeImageAvailable,
                $runtimeImageProbeFailed,
            ] = $parts;

            $items[$name] = [
                'path_exists' => $pathExists === '1',
                'root_exists' => $rootExists === '1',
                'root_inside_path' => $rootInsidePath === '1',
                'docker_available' => $dockerAvailable === '1',
                'container_exists' => $containerExists === '1',
                'container_spec_matches' => $containerSpecMatches === '1',
                'container_running' => $containerRunning === '1',
                'system_user_exists' => $systemUserExists === '1',
                'fs_permissions_ok' => $fsPermissionsOk === '1',
                'runtime_config_exists' => $runtimeConfigExists === '1',
                'runtime_config_matches' => $runtimeConfigMatches === '1',
                'runtime_image_available' => $runtimeImageAvailable === '1',
                'runtime_image_probe_failed' => $runtimeImageProbeFailed === '1',
            ];
        }

        return new ProbeSnapshot($items);
    }

    /**
     * Render the POSIX shell introspection script. Docker-first nodes
     * intentionally omit host PHP, so the script may not assume `php` is
     * available. Only POSIX `sh` builtins, `docker`, `id`, `stat`, and
     * `find` are required on the node.
     *
     * @param  array<string, string>  $variables
     */
    private function renderIntrospectScript(array $variables): string
    {
        $assignments = '';

        foreach ($variables as $key => $value) {
            $assignments .= "{$key}=".escapeshellarg($value).PHP_EOL;
        }

        return <<<SH
set -eu
{$assignments}
path_exists=0
[ -d "\$APP_PATH" ] && path_exists=1

root_rel=\${APP_DOCUMENT_ROOT#/}
root_rel=\${root_rel%/}
if [ -z "\$root_rel" ] || [ "\$root_rel" = "." ]; then
    root_path="\$APP_PATH"
else
    root_path="\$APP_PATH/\$root_rel"
fi

root_exists=0
[ -d "\$root_path" ] && root_exists=1

root_inside_path=0
case "\$root_path" in
    "\$APP_PATH"|"\$APP_PATH"/*) root_inside_path=1 ;;
esac

docker_available=0
if command -v docker >/dev/null 2>&1; then
    docker_available=1
fi

container_exists=0
container_spec_matches=0
container_running=0

if [ "\$RUNTIME_KIND" = "php" ] && [ -n "\$RUNTIME_CONTAINER_NAME" ] && [ "\$docker_available" -eq 1 ]; then
    if docker container inspect "\$RUNTIME_CONTAINER_NAME" >/dev/null 2>&1; then
        container_exists=1
        observed_hash=\$(docker container inspect --format '{{index .Config.Labels "orbit.app.spec_hash"}}' "\$RUNTIME_CONTAINER_NAME" 2>/dev/null || printf '')
        if [ -n "\$EXPECTED_SPEC_HASH" ] && [ "\$observed_hash" = "\$EXPECTED_SPEC_HASH" ]; then
            container_spec_matches=1
        fi
        running=\$(docker container inspect --format '{{.State.Running}}' "\$RUNTIME_CONTAINER_NAME" 2>/dev/null || printf 'false')
        if [ "\$running" = "true" ]; then
            container_running=1
        fi
    fi
elif [ "\$RUNTIME_KIND" = "static" ]; then
    container_spec_matches=1
fi

system_user_exists=0
if [ -n "\$RUNTIME_USER" ] && id -u "\$RUNTIME_USER" >/dev/null 2>&1; then
    system_user_exists=1
fi

fs_permissions_ok=0
if [ "\$path_exists" -eq 1 ] && [ -n "\$RUNTIME_USER" ]; then
    observed_owner=\$(stat -c '%U' "\$APP_PATH" 2>/dev/null || stat -f '%Su' "\$APP_PATH" 2>/dev/null || printf '')
    not_world_writable=\$(find "\$APP_PATH" -maxdepth 0 ! -perm /022 -print 2>/dev/null || printf '')
    if [ "\$observed_owner" = "\$RUNTIME_USER" ] && [ -n "\$not_world_writable" ]; then
        fs_permissions_ok=1
    fi
fi

runtime_config_exists=0
runtime_config_matches=0
if [ "\$RUNTIME_KIND" = "php" ] && [ -n "\$RUNTIME_CONFIG_PATH" ]; then
    if sudo test -e "\$RUNTIME_CONFIG_PATH" 2>/dev/null; then
        runtime_config_exists=1
        observed_config_hash=\$(sudo sha256sum "\$RUNTIME_CONFIG_PATH" 2>/dev/null | awk '{print \$1}' || printf '')
        if [ -n "\$EXPECTED_RUNTIME_CONFIG_HASH" ] && [ "\$observed_config_hash" = "\$EXPECTED_RUNTIME_CONFIG_HASH" ]; then
            runtime_config_matches=1
        fi
    fi
elif [ "\$RUNTIME_KIND" = "static" ]; then
    runtime_config_matches=1
fi

runtime_image_available=0
runtime_image_probe_failed=0
if [ "\$RUNTIME_KIND" = "php" ] && [ -n "\$EXPECTED_RUNTIME_IMAGE" ] && [ "\$docker_available" -eq 1 ]; then
    set +e
    image_err=\$(docker image inspect "\$EXPECTED_RUNTIME_IMAGE" 2>&1 >/dev/null)
    image_ec=\$?
    set -e
    if [ "\$image_ec" = "0" ]; then
        runtime_image_available=1
    elif printf '%s' "\$image_err" | grep -qi 'no such image'; then
        runtime_image_available=0
    else
        runtime_image_probe_failed=1
    fi
elif [ "\$RUNTIME_KIND" = "static" ]; then
    runtime_image_available=1
fi

printf '%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\t%s\\n' \\
    "\$APP_NAME" \\
    "\$path_exists" \\
    "\$root_exists" \\
    "\$root_inside_path" \\
    "\$docker_available" \\
    "\$container_exists" \\
    "\$container_spec_matches" \\
    "\$container_running" \\
    "\$system_user_exists" \\
    "\$fs_permissions_ok" \\
    "\$runtime_config_exists" \\
    "\$runtime_config_matches" \\
    "\$runtime_image_available" \\
    "\$runtime_image_probe_failed"
SH;
    }

    /**
     * Probe the node for Orbit-managed runtime config files
     * (`/etc/orbit/apps/<slug>.ini`). Returns a tri-state probe result so the
     * orchestrator can distinguish proven-absent (no orphan scan needed) from
     * sudo/SSH/probe failure (must NOT be reported as a clean empty snapshot,
     * because stale `runtime_config_extra` artifacts could be hidden).
     */
    public function introspectNodeRuntimeConfigs(Node $node): NodeRuntimeConfigsProbe
    {
        // Probe must distinguish proven-absent (exit 1, no stderr from
        // `sudo test -d`) from unknown sudo/SSH/permission failures AND from
        // a successful `sudo test -d` followed by a failing `sudo find`
        // (e.g., a permission glitch on the directory contents). All three
        // listing paths report through the `orbit-config-dir:` sentinel so
        // the orchestrator never silently treats a probe failure as a clean
        // empty snapshot — that would hide stale runtime_config_extra
        // artifacts.
        $script = <<<'BASH'
set -u
dir='/etc/orbit/apps'

dir_err="$(sudo test -d "$dir" 2>&1)"
dir_ec=$?
if [ "$dir_ec" = "1" ] && [ -z "$dir_err" ]; then
    printf 'orbit-config-dir:absent\n'
    exit 0
fi
if [ "$dir_ec" != "0" ]; then
    printf 'orbit-config-dir:error %s\n' "$dir_err"
    exit 0
fi

err_file="$(mktemp 2>/dev/null || printf '/tmp/orbit-config-dir.%d' $$)"
set +e
list_out="$(sudo find "$dir" -maxdepth 1 -type f -name '*.ini' -print 2>"$err_file")"
list_ec=$?
set -e
list_err="$(cat "$err_file" 2>/dev/null)"
rm -f "$err_file"

if [ "$list_ec" = "0" ]; then
    printf 'orbit-config-dir:present\n'
    if [ -n "$list_out" ]; then
        printf '%s\n' "$list_out"
    fi
else
    if [ -z "$list_err" ]; then
        list_err="sudo find failed (ec=$list_ec)"
    fi
    printf 'orbit-config-dir:error %s\n' "$list_err"
fi
BASH;

        try {
            $result = ($this->remoteShell ?? app(RemoteShell::class))->run($node, $script);
        } catch (\Throwable $exception) {
            // SSH / transport / remote shell construction failure: must NOT
            // abort the doctor run and must NOT be reported as a clean empty
            // snapshot — stale runtime_config_extra artifacts could be
            // hidden. Surface as Error status with the underlying error so
            // DoctorReportRunner can emit the documented
            // `app.runtime_config_probe_failed` drift.
            return new NodeRuntimeConfigsProbe(
                status: NodeRuntimeConfigsProbeStatus::Error,
                configs: new ProbeSnapshot([]),
                error: $exception->getMessage(),
            );
        }

        if (! $result->successful()) {
            // Non-zero exit without a sentinel — same contract as throw:
            // do not pretend the directory is clean/empty.
            $remoteError = trim($result->errorOutput().' '.$result->stdout);

            return new NodeRuntimeConfigsProbe(
                status: NodeRuntimeConfigsProbeStatus::Error,
                configs: new ProbeSnapshot([]),
                error: $remoteError !== '' ? $remoteError : 'remote shell call failed during managed runtime config scan',
            );
        }

        $lines = explode("\n", rtrim($result->stdout, "\n\r"));
        $status = NodeRuntimeConfigsProbeStatus::Error;
        $error = 'orbit-config-dir probe returned no status sentinel';
        $items = [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'orbit-config-dir:')) {
                $marker = substr($line, strlen('orbit-config-dir:'));

                if ($marker === 'present') {
                    $status = NodeRuntimeConfigsProbeStatus::Present;
                    $error = '';
                } elseif ($marker === 'absent') {
                    $status = NodeRuntimeConfigsProbeStatus::Absent;
                    $error = '';
                } else {
                    $status = NodeRuntimeConfigsProbeStatus::Error;
                    $error = ltrim(substr($marker, strlen('error')));

                    if ($error === '') {
                        $error = 'unknown sudo / docker probe failure';
                    }
                }

                continue;
            }

            $path = $line;
            $basename = basename($path);
            $slug = preg_replace('/\.ini$/', '', $basename) ?? '';

            if ($slug === '') {
                continue;
            }

            $items[$slug] = [
                'path' => $path,
                'app_slug' => $slug,
            ];
        }

        // Only Present probes may surface listed files; an Error sentinel
        // followed by file output should not be trusted as a clean orphan
        // list. Absent probes return an empty snapshot by definition.
        $configs = $status === NodeRuntimeConfigsProbeStatus::Present
            ? new ProbeSnapshot($items)
            : new ProbeSnapshot([]);

        return new NodeRuntimeConfigsProbe(
            status: $status,
            configs: $configs,
            error: $error,
        );
    }

    /**
     * Probe the node for Orbit-managed app runtime containers regardless of
     * gateway app records. Returns a tri-state probe result so the
     * orchestrator can distinguish:
     *
     * - **Present**: docker scanned successfully; the snapshot is keyed by
     *   the encoded app slug label and is authoritative for orphan detection.
     * - **Absent**: docker is not installed on the node (no Orbit-managed
     *   runtime containers can exist) — an empty snapshot is correct.
     * - **Error**: docker container ls failed for an unknown reason (daemon
     *   down, permission denied, SSH/remote transport error). Empty snapshot
     *   is returned so the orchestrator does NOT mistake it for a clean
     *   absence and silently hide stale `app.runtime_container_extra`
     *   artifacts.
     *
     * Probe failure must never abort the whole doctor run; the caller maps
     * Error status to a dedicated `app.runtime_container_probe_failed` drift.
     */
    public function introspectNode(Node $node): NodeRuntimeContainersProbe
    {
        $script = <<<'BASH'
set -u
if ! command -v docker >/dev/null 2>&1; then
    printf 'orbit-container-scan:absent\n'
    exit 0
fi

err_file="$(mktemp 2>/dev/null || printf '/tmp/orbit-container-scan.%d' $$)"
set +e
scan_out="$(docker container ls --all \
    --filter 'label=orbit.managed=true' \
    --filter 'label=orbit.container.kind=app-runtime' \
    --format '{{.Names}}\t{{.Label "orbit.app"}}' 2>"$err_file")"
scan_ec=$?
set -e
scan_err="$(cat "$err_file" 2>/dev/null)"
rm -f "$err_file"

if [ "$scan_ec" = "0" ]; then
    printf 'orbit-container-scan:present\n'
    if [ -n "$scan_out" ]; then
        printf '%s\n' "$scan_out"
    fi
else
    if [ -z "$scan_err" ]; then
        scan_err="docker container ls failed (ec=$scan_ec)"
    fi
    printf 'orbit-container-scan:error %s\n' "$scan_err"
fi
BASH;

        try {
            $result = ($this->remoteShell ?? app(RemoteShell::class))->run($node, $script);
        } catch (\Throwable $exception) {
            return new NodeRuntimeContainersProbe(
                status: NodeRuntimeContainersProbeStatus::Error,
                containers: new ProbeSnapshot([]),
                error: $exception->getMessage(),
            );
        }

        if (! $result->successful()) {
            $remoteError = trim($result->errorOutput().' '.$result->stdout);

            return new NodeRuntimeContainersProbe(
                status: NodeRuntimeContainersProbeStatus::Error,
                containers: new ProbeSnapshot([]),
                error: $remoteError !== '' ? $remoteError : 'remote shell call failed during app runtime container scan',
            );
        }

        $status = NodeRuntimeContainersProbeStatus::Error;
        $error = 'orbit-container-scan probe returned no status sentinel';
        $items = [];

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'orbit-container-scan:')) {
                $marker = substr($line, strlen('orbit-container-scan:'));

                if ($marker === 'present') {
                    $status = NodeRuntimeContainersProbeStatus::Present;
                    $error = '';
                } elseif ($marker === 'absent') {
                    $status = NodeRuntimeContainersProbeStatus::Absent;
                    $error = '';
                } else {
                    $status = NodeRuntimeContainersProbeStatus::Error;
                    $error = ltrim(substr($marker, strlen('error')));

                    if ($error === '') {
                        $error = 'unknown docker container ls failure';
                    }
                }

                continue;
            }

            $parts = explode("\t", $rawLine, 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$containerName, $appSlug] = $parts;
            $appSlug = trim($appSlug);

            if ($appSlug === '') {
                continue;
            }

            $items[$appSlug] = [
                'container_name' => trim($containerName),
                'app_slug' => $appSlug,
            ];
        }

        // Only Present probes surface listed containers; Absent/Error return
        // an empty snapshot so doctor cannot mistake them for a clean orphan
        // list.
        $containers = $status === NodeRuntimeContainersProbeStatus::Present
            ? new ProbeSnapshot($items)
            : new ProbeSnapshot([]);

        return new NodeRuntimeContainersProbe(
            status: $status,
            containers: $containers,
            error: $error,
        );
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(App $app, ProbeSnapshot $snapshot): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkRecordCompleteness($app));
        $drift = array_merge($drift, $this->checkOwnerNode($app));
        $drift = array_merge($drift, $this->checkSourcePath($app, $snapshot));
        $drift = array_merge($drift, $this->checkDocumentRoot($app, $snapshot));
        $drift = array_merge($drift, $this->checkPhpRuntime($app, $snapshot));
        $drift = array_merge($drift, $this->checkRuntimeContainer($app, $snapshot));
        $drift = array_merge($drift, $this->checkRuntimeConfig($app, $snapshot));
        $drift = array_merge($drift, $this->checkProductionSecurity($app, $snapshot));
        $drift = array_merge($drift, $this->checkAgentIdeDefault($app));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(App $app): array
    {
        if (
            ! is_string($app->name)
            || $app->name === ''
            || ! is_int($app->node_id)
            || ! is_string($app->environment)
            || $app->environment === ''
            || ! is_string($app->path)
            || $app->path === ''
            || ! is_string($app->document_root)
            || $app->document_root === ''
            || ! is_string($app->php_version)
            || $app->php_version === ''
            || ! is_bool($app->adopted)
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "App record for {$app->name} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeContainer(App $app, ProbeSnapshot $snapshot): array
    {
        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            return [];
        }

        $observed = $snapshot->get($app->name);

        if (
            $observed === null
            || ($observed['path_exists'] ?? null) === false
            || ($observed['docker_available'] ?? null) === false
            // When the selected image is proven absent the runtime container
            // cannot exist either; checkPhpRuntime emits the canonical
            // `app.php_version_unavailable` drift in that case. Unknown
            // image-probe failures (Docker daemon unreachable, permission,
            // transport error) do NOT short-circuit here — they fall through
            // to surface as the documented `app.runtime_container_missing`
            // (when no container exists) or `app.runtime_container_mismatch`
            // (when one does) so the doctor restore path can re-attempt apply.
        ) {
            return [];
        }

        if (($observed['runtime_image_available'] ?? null) === false
            && ($observed['runtime_image_probe_failed'] ?? null) !== true) {
            // Image proven missing: checkPhpRuntime owns the
            // `app.php_version_unavailable` drift; suppress container drift
            // because the container cannot exist without its image.
            return [];
        }

        if (($observed['container_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.runtime_container_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$app->name} FrankenPHP runtime container is missing on the owning app node.",
                    detail: [
                        'expected' => $this->appRuntimeContainerRenderer()->containerName($app),
                    ],
                ),
            ];
        }

        if (($observed['container_spec_matches'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.runtime_container_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "App {$app->name} FrankenPHP runtime container differs from gateway app configuration.",
                    detail: [
                        'expected' => $this->appRuntimeContainerRenderer()->containerName($app),
                    ],
                ),
            ];
        }

        // A stopped container exposes no runtime endpoint, so doctor docs
        // treat it as `app.runtime_container_missing` ("absent endpoint")
        // even though the container record itself still exists. Restore
        // restarts it via AppRuntimeContainerManager::apply().
        if (($observed['container_running'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.runtime_container_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$app->name} FrankenPHP runtime container is stopped; runtime endpoint is absent.",
                    detail: [
                        'expected' => $this->appRuntimeContainerRenderer()->containerName($app),
                        'reason' => 'container_stopped',
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeConfig(App $app, ProbeSnapshot $snapshot): array
    {
        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            return [];
        }

        $observed = $snapshot->get($app->name);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        $expectedPath = "/etc/orbit/apps/{$app->name}.ini";

        if (($observed['runtime_config_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.runtime_config_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$app->name} managed runtime configuration is missing on the owning app node.",
                    detail: [
                        'app' => $app->name,
                        'expected' => $expectedPath,
                    ],
                ),
            ];
        }

        if (($observed['runtime_config_matches'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.runtime_config_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "App {$app->name} managed runtime configuration differs from gateway app configuration.",
                    detail: [
                        'app' => $app->name,
                        'expected' => $expectedPath,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkPhpRuntime(App $app, ProbeSnapshot $snapshot): array
    {
        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            return [];
        }

        if (! $this->phpRuntimeCatalog()->supports($app->php_version)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "PHP {$app->php_version} is not a supported FrankenPHP runtime image for app {$app->name}.",
                    detail: [
                        'php_version' => $app->php_version,
                    ],
                ),
            ];
        }

        $observed = $snapshot->get($app->name);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        if (($observed['docker_available'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "Docker is not available to serve PHP {$app->php_version} for app {$app->name} on the owning app node.",
                    detail: [
                        'php_version' => $app->php_version,
                    ],
                ),
            ];
        }

        // Unknown image probe failures (Docker daemon unreachable, permission
        // denied, SSH transport error) MUST NOT collapse into
        // `app.php_version_unavailable` — that drift means the selected
        // FrankenPHP image is proven missing on the node. Suppress here;
        // checkRuntimeContainer surfaces the documented
        // `app.runtime_container_missing` / `app.runtime_container_mismatch`
        // drift instead, so doctor restore can re-attempt apply through the
        // manager's image preflight (which throws
        // AppRuntimeContainerApplyException on persistent probe failure).
        if (($observed['runtime_image_probe_failed'] ?? null) === true) {
            return [];
        }

        if (($observed['runtime_image_available'] ?? null) === false) {
            $expectedImage = $this->expectedImageOrEmpty($app);

            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "FrankenPHP runtime image for PHP {$app->php_version} is not available on the owning app node for app {$app->name}.",
                    detail: [
                        'php_version' => $app->php_version,
                        'expected_image' => $expectedImage,
                    ],
                ),
            ];
        }

        return [];
    }

    private function expectedImageOrEmpty(App $app): string
    {
        try {
            return $this->appRuntimeContainerRenderer()->render($app)->image();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkProductionSecurity(App $app, ProbeSnapshot $snapshot): array
    {
        if (! $this->isProductionApp($app)) {
            return [];
        }

        $observed = $snapshot->get($app->name);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        if (! array_key_exists('system_user_exists', $observed)) {
            return [];
        }

        $drift = [];

        if (($observed['system_user_exists'] ?? null) === false) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'app.security.system_user',
                kind: DriftKind::Missing,
                summary: "Production app {$app->name} is missing its expected runtime user.",
                detail: [
                    'app' => $app->name,
                    'runtime_user' => $this->appRuntimeUser()->forApp($app),
                ],
            );
        }

        if (($observed['fs_permissions_ok'] ?? null) === false) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'app.security.fs_permissions',
                kind: DriftKind::Divergent,
                summary: "Production app {$app->name} filesystem permissions do not match runtime policy.",
                detail: [
                    'app' => $app->name,
                    'path' => $app->path,
                    'runtime_user' => $this->appRuntimeUser()->forApp($app),
                ],
            );
        }

        if (
            $app->runtime_kind === AppRuntimeKind::Php
            && ($observed['docker_available'] ?? null) === true
            && (
                ($observed['container_exists'] ?? null) === false
                || ($observed['container_spec_matches'] ?? null) === false
            )
        ) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'app.security.runtime_container_isolation',
                kind: $observed['container_exists'] === false ? DriftKind::Missing : DriftKind::Divergent,
                summary: "Production app {$app->name} runtime container isolation does not match security policy.",
                detail: [
                    'app' => $app->name,
                    'expected' => $this->appRuntimeContainerRenderer()->containerName($app),
                ],
            );
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkOwnerNode(App $app): array
    {
        $app->loadMissing('node');

        if (! $app->node instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.owner_node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "App {$app->name} points at a missing owning node.",
                ),
            ];
        }

        $requiredRole = $app->environment === 'production' ? 'app-prod' : 'app-dev';

        if (! $app->node->isActive() || ! $this->nodeRoleAssignments()->nodeHasActiveRole($app->node, $requiredRole)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.owner_node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "App {$app->name} is owned by node {$app->node->name}, which is not an active app node.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkSourcePath(App $app, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($app->name);

        if ($observed === null) {
            return [];
        }

        if (($observed['path_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.path_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$app->name} path is missing on the owning app node.",
                    detail: [
                        'expected' => $app->path,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDocumentRoot(App $app, ProbeSnapshot $snapshot): array
    {
        if ($app->path === '' || $app->document_root === '') {
            return [];
        }

        if ($this->documentRootEscapesPath($app)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.root_outside_path',
                    kind: DriftKind::Divergent,
                    summary: "App {$app->name} document root resolves outside the app path.",
                    detail: [
                        'path' => $app->path,
                        'document_root' => $app->document_root,
                    ],
                ),
            ];
        }

        $observed = $snapshot->get($app->name);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        if (($observed['root_inside_path'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.root_outside_path',
                    kind: DriftKind::Divergent,
                    summary: "App {$app->name} document root resolves outside the app path.",
                    detail: [
                        'path' => $app->path,
                        'document_root' => $app->document_root,
                    ],
                ),
            ];
        }

        if (($observed['root_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.root_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$app->name} document root is missing on the owning app node.",
                    detail: [
                        'expected' => $app->documentRootPath(),
                    ],
                ),
            ];
        }

        return [];
    }

    private function documentRootEscapesPath(App $app): bool
    {
        $path = $this->normalizePath($app->path);
        $root = trim($app->document_root, '/');
        $rootPath = $root === '' ? $path : $this->normalizePath("{$app->path}/{$root}");

        return $rootPath !== $path && ! str_starts_with($rootPath, "{$path}/");
    }

    private function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAgentIdeDefault(App $app): array
    {
        $config = $app->agent_ide_config ?? [];

        if (! is_array($config) || $config === []) {
            return [];
        }

        $adapter = $config['adapter'] ?? null;

        if ($adapter === null || $adapter === '') {
            return [];
        }

        if (! is_string($adapter) || ! in_array($adapter, $this->agentIdeDefaults()->supportedAdapters(), true)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.agent_ide_default_invalid',
                    kind: DriftKind::Divergent,
                    summary: "App agent IDE adapter for {$app->name} is not supported.",
                ),
            ];
        }

        return [];
    }

    private function appRuntimeUser(): AppRuntimeUser
    {
        return $this->appRuntimeUser ?? app(AppRuntimeUser::class);
    }

    private function appRuntimeContainerRenderer(): AppRuntimeContainerRenderer
    {
        return $this->appRuntimeContainerRenderer
            ?? app(AppRuntimeContainerRenderer::class);
    }

    private function phpRuntimeCatalog(): PhpRuntimeCatalog
    {
        return $this->phpRuntimeCatalog ?? app(PhpRuntimeCatalog::class);
    }

    private function agentIdeDefaults(): AppAgentIdeDefaults
    {
        return $this->agentIdeDefaults ?? app(AppAgentIdeDefaults::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    private function isProductionApp(App $app): bool
    {
        $app->loadMissing('node');

        return $app->environment === 'production'
            || ($app->node instanceof Node && $this->nodeRoleAssignments()->nodeHasActiveRole($app->node, 'app-prod'));
    }
}
