<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\Proxy\IngressResolver;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Tools\CaddyTool;
use RuntimeException;
use Throwable;

final readonly class EnsureWorkspaceProxyRoute
{
    public function __construct(
        private RemoteShell $remoteShell,
        private WorkspaceRuntimeContainerRenderer $runtimeContainerRenderer,
        private SiteCertificateInstaller $siteCertificateInstaller,
        private CaddyGlobalConfig $caddyGlobalConfig,
        private IngressResolver $ingressResolver,
        private ProxyRouteRenderer $proxyRouteRenderer,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(Workspace $workspace): array
    {
        $workspace->loadMissing(['app', 'app.node']);

        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new RuntimeException("Workspace '{$workspace->name}' has no parent app.");
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $domain = $this->domain($workspace, $app, $node);
        [$servingNode, $config, $content] = $this->routeArtifact($workspace, $app, $node, $domain);

        ProxyRoute::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'node_id' => $servingNode->id,
                'app_id' => $app->id,
                'workspace_id' => $workspace->id,
                'owner_type' => 'workspace',
                'kind' => 'workspace',
                'config' => $config,
                'source_hash' => hash('sha256', $content),
            ],
        );

        try {
            $this->siteCertificateInstaller->ensureFor($servingNode, $domain);
            $this->ensureGlobalCaddyfile($servingNode);
        } catch (Throwable) {
            return [[
                'code' => 'proxy.enactment_failed',
                'family' => 'proxy',
                'message' => "Proxy route '{$domain}' was recorded, but TLS material could not be installed. Run doctor to converge proxy artifacts.",
                'next_command' => 'doctor --family=proxy --restore',
            ]];
        }

        $result = $this->remoteShell->run($servingNode, $this->renderInstallScript($domain, $content));

        if (! $result->successful()) {
            return [[
                'code' => 'proxy.enactment_failed',
                'family' => 'proxy',
                'message' => "Proxy route '{$domain}' was recorded, but backend enactment failed. Run doctor to converge proxy artifacts.",
                'next_command' => 'doctor --family=proxy --restore',
            ]];
        }

        if (($config['placement'] ?? null) === 'ingress') {
            $routerArtifact = $config['router_artifact'] ?? null;

            if (! is_array($routerArtifact)) {
                throw new RuntimeException("Proxy route '{$domain}' is missing a router artifact.");
            }

            $routerNodeId = $routerArtifact['node_id'] ?? null;
            $routerNode = is_int($routerNodeId) ? Node::query()->find($routerNodeId) : null;

            if (! $routerNode instanceof Node) {
                throw new RuntimeException("Proxy route '{$domain}' points at an unavailable router node.");
            }

            $routerContent = $this->proxyRouteRenderer->renderRouterRoute(new ProxyRoute([
                'node_id' => $routerNode->id,
                'domain' => $domain,
                'kind' => 'workspace',
                'owner_type' => 'workspace',
                'app_id' => $app->id,
                'workspace_id' => $workspace->id,
                'config' => $config,
            ]));

            $this->ensureGlobalCaddyfile($routerNode);
            $routerResult = $this->remoteShell->run($routerNode, $this->renderInstallScript($domain, $routerContent));

            if (! $routerResult->successful()) {
                return [[
                    'code' => 'proxy.enactment_failed',
                    'family' => 'proxy',
                    'message' => "Proxy route '{$domain}' was recorded, but router enactment failed. Run doctor to converge proxy artifacts.",
                    'next_command' => 'doctor --family=proxy --restore',
                ]];
            }

            $backendArtifact = $config['backend_artifacts'][0] ?? null;

            if (! is_array($backendArtifact)) {
                throw new RuntimeException("Proxy route '{$domain}' is missing a backend artifact.");
            }

            $backendContent = $this->proxyRouteRenderer->renderPrivateBackend(
                new ProxyRoute([
                    'domain' => $domain,
                    'kind' => 'workspace',
                    'owner_type' => 'workspace',
                    'app_id' => $app->id,
                    'workspace_id' => $workspace->id,
                    'config' => $config,
                ]),
                $backendArtifact,
            );

            $this->ensureGlobalCaddyfile($node);
            $backendResult = $this->remoteShell->run($node, $this->renderInstallScript($domain, $backendContent, backend: true));

            if (! $backendResult->successful()) {
                return [[
                    'code' => 'proxy.enactment_failed',
                    'family' => 'proxy',
                    'message' => "Proxy route '{$domain}' was recorded, but backend enactment failed. Run doctor to converge proxy artifacts.",
                    'next_command' => 'doctor --family=proxy --restore',
                ]];
            }
        }

        return [];
    }

    private function ensureGlobalCaddyfile(Node $node): void
    {
        $readResult = $this->remoteShell->run(
            $node,
            'sudo test -f /etc/caddy/Caddyfile && sudo cat /etc/caddy/Caddyfile || true',
            ['throw' => true],
        );

        $updated = $this->caddyGlobalConfig->ensure($readResult->stdout);

        if ($updated === $readResult->stdout) {
            return;
        }

        $this->remoteShell->run(
            $node,
            sprintf(
                'sudo install -d -m 0755 /etc/caddy && printf %%s %s | base64 -d | sudo tee /etc/caddy/Caddyfile >/dev/null',
                escapeshellarg(base64_encode($updated)),
            ),
            ['throw' => true],
        );
    }

    /**
     * @param  array{
     *     document_root: string,
     *     runtime_upstream?: string|null,
     *     php_socket?: string|null,
     *     tls: array{cert_path: string, key_path: string},
     * }  $config
     */
    private function renderCaddySite(Workspace $workspace, App $app, string $domain, array $config): string
    {
        $pathBlocking = $app->document_root === '.'
            ? 'import path_blocking_project_root'
            : 'import path_blocking_public_root';

        if ($app->runtime_kind === AppRuntimeKind::Php) {
            $upstream = $config['runtime_upstream'] ?? null;

            if (! is_string($upstream) || $upstream === '') {
                throw new RuntimeException("Workspace '{$workspace->name}' route is missing a runtime container upstream.");
            }

            return <<<CADDY
{$domain} {
    tls {$config['tls']['cert_path']} {$config['tls']['key_path']}
    encode gzip

    import security_headers
    import profiling_headers
    {$pathBlocking}
    import security_txt
    import cache_headers

    reverse_proxy {$upstream} {
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {scheme}
    }
}

CADDY;
        }

        return <<<CADDY
{$domain} {
    tls {$config['tls']['cert_path']} {$config['tls']['key_path']}
    root * {$config['document_root']}
    encode gzip

    import security_headers
    import profiling_headers
    {$pathBlocking}
    import security_txt
    import cache_headers
    file_server
}

CADDY;
    }

    private function renderInstallScript(string $domain, string $content, bool $backend = false): string
    {
        $suffix = $backend ? '.backend' : '';
        $sitePath = "/etc/caddy/sites/{$domain}{$suffix}.caddy";

        return sprintf(
            <<<'SH'
sudo install -d -m 0755 /etc/caddy /etc/caddy/sites
printf %%s %s | base64 -d | sudo tee %s >/dev/null
%s
SH,
            escapeshellarg(base64_encode($content)),
            escapeshellarg($sitePath),
            CaddyTool::reloadCommand(),
        );
    }

    private function domain(Workspace $workspace, App $app, Node $node): string
    {
        if ($app->environment === 'production' && is_string($app->domain) && $app->domain !== '') {
            return "{$workspace->name}.{$app->domain}";
        }

        $tld = is_string($node->tld) ? trim($node->tld, '.') : '';

        if ($tld === '') {
            return "{$workspace->name}.{$app->name}";
        }

        return "{$workspace->name}.{$app->name}.{$tld}";
    }

    private function documentRoot(Workspace $workspace, App $app): string
    {
        $root = trim((string) $app->document_root, '/');

        if ($root === '') {
            return rtrim((string) $workspace->path, '/');
        }

        return rtrim((string) $workspace->path, '/').'/'.$root;
    }

    /**
     * @return array{0: Node, 1: array<string, mixed>, 2: string}
     */
    private function routeArtifact(Workspace $workspace, App $app, Node $node, string $domain): array
    {
        $isPhp = $app->runtime_kind === AppRuntimeKind::Php;
        $runtimeUpstream = $isPhp ? $this->runtimeContainerRenderer->upstreamUrl($workspace) : null;

        if ($app->environment !== 'production') {
            $certificatePaths = $this->siteCertificateInstaller->expectedPathsFor($node, $domain);
            $config = [
                'document_root' => $this->documentRoot($workspace, $app),
                'runtime_upstream' => $runtimeUpstream,
                'php_socket' => null,
                'tls' => [
                    'cert_path' => $certificatePaths['cert'],
                    'key_path' => $certificatePaths['key'],
                ],
            ];

            return [$node, $config, $this->renderCaddySite($workspace, $app, $domain, $config)];
        }

        $ingressNode = $this->ingressResolver->forAppNode($node);
        $routerNode = $this->ingressResolver->router();
        $certificatePaths = $this->siteCertificateInstaller->expectedPathsFor($ingressNode, $domain);
        $backendArtifact = [
            'node_id' => $node->id,
            'domain' => $domain,
            'bind' => $node->wireguard_address,
            'document_root' => $this->documentRoot($workspace, $app),
            'runtime_upstream' => $runtimeUpstream,
            'php_socket' => null,
        ];
        $config = [
            'placement' => 'ingress',
            'ingress_node_id' => $ingressNode->id,
            'router_upstream' => [
                'node_id' => $routerNode->id,
                'node' => $routerNode->name,
                'url' => $this->ingressResolver->routerUrl($routerNode),
            ],
            'router_backend_pool' => [
                [
                    'node_id' => $node->id,
                    'node' => $node->name,
                    'url' => $this->ingressResolver->backendUrl($node),
                ],
            ],
            'backend_artifacts' => [$backendArtifact],
            'tls' => [
                'cert_path' => $certificatePaths['cert'],
                'key_path' => $certificatePaths['key'],
            ],
        ];

        $routerContent = $this->proxyRouteRenderer->renderRouterRoute(new ProxyRoute([
            'node_id' => $routerNode->id,
            'domain' => $domain,
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
            'config' => $config,
        ]));
        $config['router_artifact'] = [
            'node_id' => $routerNode->id,
            'node' => $routerNode->name,
            'source_hash' => hash('sha256', $routerContent),
        ];

        $content = $this->proxyRouteRenderer->renderIngress(new ProxyRoute([
            'node_id' => $ingressNode->id,
            'domain' => $domain,
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
            'config' => $config,
        ]));

        $backendArtifact['source_hash'] = hash('sha256', $this->proxyRouteRenderer->renderPrivateBackend(
            new ProxyRoute([
                'domain' => $domain,
                'app_id' => $app->id,
                'workspace_id' => $workspace->id,
                'owner_type' => 'workspace',
                'kind' => 'workspace',
                'config' => ['placement' => 'ingress'],
            ]),
            $backendArtifact,
        ));
        $config['backend_artifacts'] = [$backendArtifact];

        return [$ingressNode, $config, $content];
    }
}
