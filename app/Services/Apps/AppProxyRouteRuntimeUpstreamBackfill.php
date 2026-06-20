<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\App;
use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteRenderer;

/**
 * Normalize legacy app proxy route configs (and nested backend artifacts) so
 * they carry a Docker-first `runtime_upstream` derived from the app identity,
 * AND persist the expected source hashes of the Docker-first rendered routes.
 *
 * Used by the 2026_05_21_130000 migration to convert origin/main rows that
 * persisted only a `php_socket`. Without the hash update, ProxyRouteProbe
 * could report a legacy `php_fastcgi` Caddy file healthy after the migration
 * because the observed file hash still matches the pre-migration `source_hash`
 * / `backend_artifacts[*].source_hash`. The hash recomputation forces doctor
 * to detect drift and converge to the Docker-first `reverse_proxy` content.
 *
 * Static apps are skipped (their routes have no runtime_upstream — they serve
 * via file_server / document_root and were never rendered with php_fastcgi).
 */
final readonly class AppProxyRouteRuntimeUpstreamBackfill
{
    public function __construct(
        private ?ProxyRouteRenderer $renderer = null,
    ) {}

    public function run(): void
    {
        $renderer = $this->renderer ?? new ProxyRouteRenderer;

        ProxyRoute::query()
            ->where('kind', 'app')
            ->whereNotNull('app_id')
            ->with('app')
            ->orderBy('id')
            ->cursor()
            ->each(function (ProxyRoute $route) use ($renderer): void {
                $app = $route->app;

                if (! $app instanceof App) {
                    return;
                }

                $runtimeKindValue = $app->runtime_kind instanceof \BackedEnum
                    ? $app->runtime_kind->value
                    : (string) $app->runtime_kind;

                if ($runtimeKindValue !== 'php') {
                    return;
                }

                $config = is_array($route->config) ? $route->config : [];

                if ($config === []) {
                    return;
                }

                $upstream = "http://orbit-app-{$app->name}:".AppRuntimeContainerRenderer::InternalPort;
                $configChanged = false;

                if (! isset($config['runtime_upstream']) || ! is_string($config['runtime_upstream']) || $config['runtime_upstream'] === '') {
                    $config['runtime_upstream'] = $upstream;
                    $configChanged = true;
                }

                if (array_key_exists('php_socket', $config) && $config['php_socket'] !== null) {
                    $config['php_socket'] = null;
                    $configChanged = true;
                }

                if (isset($config['backend_artifacts']) && is_array($config['backend_artifacts'])) {
                    foreach ($config['backend_artifacts'] as $index => $artifact) {
                        if (! is_array($artifact)) {
                            continue;
                        }

                        if (! isset($artifact['runtime_upstream']) || ! is_string($artifact['runtime_upstream']) || $artifact['runtime_upstream'] === '') {
                            $config['backend_artifacts'][$index]['runtime_upstream'] = $upstream;
                            $configChanged = true;
                        }

                        if (array_key_exists('php_socket', $artifact) && $artifact['php_socket'] !== null) {
                            $config['backend_artifacts'][$index]['php_socket'] = null;
                            $configChanged = true;
                        }
                    }
                }

                if (! $configChanged) {
                    return;
                }

                $isIngress = ($config['placement'] ?? null) === 'ingress';
                $update = ['config' => $config];

                // Render via ProxyRouteRenderer against the updated config so
                // the persisted hash matches the Docker-first artifact, not
                // the legacy php_fastcgi rendering. Use a transient route
                // built from the updated row so the renderer sees the new
                // config without persisting twice.
                $renderRoute = $this->buildTransientRoute($route, $config, $app);

                if (! $isIngress) {
                    $content = $renderer->render($renderRoute);
                    $update['source_hash'] = hash('sha256', $content);
                } else {
                    foreach ($config['backend_artifacts'] as $index => $artifact) {
                        if (! is_array($artifact)) {
                            continue;
                        }

                        $artifactContent = $renderer->renderPrivateBackend($renderRoute, $artifact);
                        $config['backend_artifacts'][$index]['source_hash'] = hash('sha256', $artifactContent);
                    }

                    $update['config'] = $config;
                }

                $route->forceFill($update)->save();
            });
    }

    /**
     * Build a non-persisted ProxyRoute carrying the updated config so the
     * renderer sees the Docker-first values. We keep the original row's
     * identity (domain, kind, owner_type, app relationship) for any
     * renderer code paths that read those.
     *
     * @param  array<string, mixed>  $config
     */
    private function buildTransientRoute(ProxyRoute $route, array $config, App $app): ProxyRoute
    {
        $transient = new ProxyRoute([
            'node_id' => $route->node_id,
            'app_id' => $route->app_id,
            'domain' => $route->domain,
            'owner_type' => $route->owner_type,
            'kind' => $route->kind,
            'config' => $config,
        ]);
        $transient->setRelation('app', $app);

        return $transient;
    }
}
