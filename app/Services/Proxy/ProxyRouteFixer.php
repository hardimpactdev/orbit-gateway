<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Ca\OrbitCaService;
use App\Services\Runtime\OrbitContainerNames;
use App\Tools\CaddyTool;

final readonly class ProxyRouteFixer
{
    public function __construct(
        private RemoteShell $remoteShell,
        private ProxyRouteRenderer $renderer,
        private OrbitCaService $ca,
        private SiteCertificateInstaller $siteCertificateInstaller,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(ProxyRoute $route, DriftEntry $entry): ?array
    {
        if (! in_array($entry->key, [
            'proxy.route_missing',
            'proxy.route_mismatch',
            'proxy.public_route_missing',
            'proxy.public_route_mismatch',
            'proxy.router_route_missing',
            'proxy.router_route_mismatch',
            'proxy.backend_route_missing',
            'proxy.backend_route_mismatch',
            'proxy.tls_missing',
            'proxy.tls_mismatch',
        ], true)) {
            return null;
        }

        if (! in_array($route->kind, ['app', 'workspace', 'proxy', 'redirect'], true)) {
            return null;
        }

        $route->loadMissing('node');

        if (in_array($entry->key, ['proxy.backend_route_missing', 'proxy.backend_route_mismatch'], true)) {
            return $this->repairBackendRoute($route, $entry);
        }

        if (in_array($entry->key, ['proxy.router_route_missing', 'proxy.router_route_mismatch'], true)) {
            return $this->repairRouterRoute($route, $entry);
        }

        if (in_array($entry->key, ['proxy.tls_missing', 'proxy.tls_mismatch'], true)) {
            $this->repairTls($route);

            return [
                'family' => 'proxy',
                'node' => $route->node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'fix',
                'status' => 'completed',
                'summary' => "Repaired Orbit-managed TLS material for proxy route {$route->domain}.",
                'details' => [
                    'route' => $route->domain,
                ],
            ];
        }

        $content = $this->renderer->render($route);
        $this->ensureSiteCertificateForOwnedPhpRoute($route);

        if ($route->owner_type === 'router') {
            $this->ensureRouterTrustPool($route->node, $route);
        }

        $this->remoteShell->run($route->node, $this->installScript($route->node, $route->domain, $content), ['throw' => true]);

        $route->forceFill([
            'source_hash' => hash('sha256', $content),
        ])->save();

        return [
            'family' => 'proxy',
            'node' => $route->node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => $this->publicRouteSummary($route, $entry),
            'details' => [
                'route' => $route->domain,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function repairRouterRoute(ProxyRoute $route, DriftEntry $entry): ?array
    {
        $artifact = $this->routerArtifact($route);
        $nodeId = $artifact['node_id'] ?? null;
        $routerNode = is_int($nodeId) ? Node::query()->find($nodeId) : null;

        if (! $routerNode instanceof Node) {
            return null;
        }

        $content = $this->renderer->renderRouterRoute($route);
        $this->ensureRouterTrustPool($routerNode, $route);
        $this->remoteShell->run($routerNode, $this->installScript($routerNode, $route->domain, $content), ['throw' => true]);

        $this->updateRouterArtifactHash($route, hash('sha256', $content));

        return [
            'family' => 'proxy',
            'node' => $routerNode->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-applied private router route {$route->domain} from gateway intent.",
            'details' => [
                'route' => $route->domain,
                'router_node_id' => $nodeId,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function repairBackendRoute(ProxyRoute $route, DriftEntry $entry): ?array
    {
        $artifact = $this->backendArtifactForEntry($route, $entry);

        if ($artifact === null) {
            return null;
        }

        $nodeId = $artifact['node_id'] ?? null;
        $backendNode = is_int($nodeId) ? Node::query()->find($nodeId) : null;

        if (! $backendNode instanceof Node) {
            return null;
        }

        $content = $this->renderer->renderPrivateBackend($route, $artifact);
        $this->remoteShell->run($backendNode, $this->installScript($backendNode, $route->domain, $content, backend: true), ['throw' => true]);

        $this->updateBackendArtifactHash($route, $nodeId, hash('sha256', $content));

        return [
            'family' => 'proxy',
            'node' => $backendNode->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-applied private backend route {$route->domain} on {$backendNode->name} from gateway intent.",
            'details' => [
                'route' => $route->domain,
                'backend_node_id' => $nodeId,
            ],
        ];
    }

    private function repairTls(ProxyRoute $route): void
    {
        if ($this->ensureSiteCertificateForOwnedPhpRoute($route)) {
            return;
        }

        $leaf = $this->ca->issueLeaf($route->domain);
        $paths = $this->tlsPaths($route);

        $this->remoteShell->run(
            $route->node,
            $this->tlsInstallScript(
                $paths['cert'],
                $paths['key'],
                (string) file_get_contents($leaf['cert']),
                (string) file_get_contents($leaf['key']),
                $this->caddyReloadCommand($route->node),
            ),
            ['throw' => true],
        );
    }

    private function ensureSiteCertificateForOwnedPhpRoute(ProxyRoute $route): bool
    {
        if (! in_array($route->kind, ['app', 'workspace'], true)) {
            return false;
        }

        $this->siteCertificateInstaller->ensureFor($route->node, $route->domain);

        return true;
    }

    private function installScript(Node $node, string $domain, string $content, bool $backend = false): string
    {
        $suffix = $backend ? '.backend' : '';
        $sitePath = "/etc/caddy/sites/{$domain}{$suffix}.caddy";

        return sprintf(
            <<<'SH'
sudo install -d -m 0755 /etc/caddy/sites
printf %%s %s | base64 -d | sudo tee %s >/dev/null
%s
SH,
            escapeshellarg(base64_encode($content)),
            escapeshellarg($sitePath),
            $this->caddyReloadCommand($node),
        );
    }

    private function ensureRouterTrustPool(Node $node, ProxyRoute $route): void
    {
        $config = is_array($route->config) ? $route->config : [];
        $backendTls = $config['router_backend_tls'] ?? null;

        if (! is_array($backendTls) || ($backendTls['trusted_by_gateway_ca'] ?? null) !== true) {
            return;
        }

        $caPath = is_string($backendTls['ca_path'] ?? null) && $backendTls['ca_path'] !== ''
            ? $backendTls['ca_path']
            : '/etc/orbit/ca/root.crt';

        $this->remoteShell->run($node, $this->trustPoolInstallScript($route, $caPath), ['throw' => true]);
    }

    private function trustPoolInstallScript(ProxyRoute $route, string $caPath): string
    {
        $caPath = $this->validatedAbsolutePath($route, $caPath, 'has an invalid router backend CA path.');

        return sprintf(
            <<<'SH'
sudo install -d -m 0755 %s
printf %%s %s | base64 -d | sudo tee %s >/dev/null
sudo chmod 0644 %s
SH,
            escapeshellarg(dirname($caPath)),
            escapeshellarg(base64_encode($this->ca->rootCert())),
            escapeshellarg($caPath),
            escapeshellarg($caPath),
        );
    }

    private function publicRouteSummary(ProxyRoute $route, DriftEntry $entry): string
    {
        if (in_array($entry->key, ['proxy.public_route_missing', 'proxy.public_route_mismatch'], true)) {
            return "Re-applied public proxy route {$route->domain} from gateway intent.";
        }

        return "Re-applied proxy route {$route->domain} from gateway intent.";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function backendArtifactForEntry(ProxyRoute $route, DriftEntry $entry): ?array
    {
        $backendNodeId = $entry->detail['backend_node_id'] ?? null;

        if (! is_int($backendNodeId)) {
            return null;
        }

        foreach ($this->backendArtifacts($route) as $artifact) {
            if (($artifact['node_id'] ?? null) === $backendNodeId) {
                return $artifact;
            }
        }

        return null;
    }

    private function updateBackendArtifactHash(ProxyRoute $route, int $nodeId, string $hash): void
    {
        $config = is_array($route->config) ? $route->config : [];
        $artifacts = $config['backend_artifacts'] ?? [];

        if (! is_array($artifacts)) {
            return;
        }

        foreach ($artifacts as $index => $artifact) {
            if (! is_array($artifact) || ($artifact['node_id'] ?? null) !== $nodeId) {
                continue;
            }

            $artifacts[$index]['source_hash'] = $hash;
        }

        $config['backend_artifacts'] = $artifacts;

        $route->forceFill(['config' => $config])->save();
    }

    private function updateRouterArtifactHash(ProxyRoute $route, string $hash): void
    {
        $config = is_array($route->config) ? $route->config : [];
        $artifact = $config['router_artifact'] ?? null;

        if (! is_array($artifact)) {
            return;
        }

        $artifact['source_hash'] = $hash;
        $config['router_artifact'] = $artifact;

        $route->forceFill(['config' => $config])->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function backendArtifacts(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $artifacts = $config['backend_artifacts'] ?? null;

        if (! is_array($artifacts)) {
            return [];
        }

        return array_values(array_filter($artifacts, is_array(...)));
    }

    /**
     * @return array<string, mixed>
     */
    private function routerArtifact(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $artifact = $config['router_artifact'] ?? null;

        return is_array($artifact) ? $artifact : [];
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function tlsPaths(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $tls = is_array($config['tls'] ?? null) ? $config['tls'] : [];
        $cert = $tls['cert_path'] ?? null;
        $key = $tls['key_path'] ?? null;

        return [
            'cert' => $this->validatedAbsolutePath(
                $route,
                is_string($cert) && $cert !== '' ? $cert : "/etc/orbit/certs/{$route->domain}.crt",
                'has an invalid TLS cert path.',
            ),
            'key' => $this->validatedAbsolutePath(
                $route,
                is_string($key) && $key !== '' ? $key : "/etc/orbit/certs/{$route->domain}.key",
                'has an invalid TLS key path.',
            ),
        ];
    }

    private function tlsInstallScript(string $certPath, string $keyPath, string $cert, string $key, string $reloadCommand): string
    {
        return sprintf(
            <<<'SH'
sudo install -d -m 0755 %s %s
printf %%s %s | base64 -d | sudo tee %s >/dev/null
printf %%s %s | base64 -d | sudo tee %s >/dev/null
sudo chmod 0644 %s
sudo chmod 0600 %s
%s
SH,
            escapeshellarg(dirname($certPath)),
            escapeshellarg(dirname($keyPath)),
            escapeshellarg(base64_encode($cert)),
            escapeshellarg($certPath),
            escapeshellarg(base64_encode($key)),
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
            escapeshellarg($keyPath),
            $reloadCommand,
        );
    }

    /**
     * Restore the orbit-caddy container on a serving node when proxy probing
     * reports the container is missing or stopped. `proxy.caddy_container_down`
     * starts the existing container; `proxy.caddy_container_missing` reconciles
     * the container from its managed spec so mounted route artifacts are
     * served again.
     *
     * @return array<string, mixed>|null
     */
    public function fixCaddyContainer(Node $node, DriftEntry $entry): ?array
    {
        $caddyName = $this->caddyContainerName($node);

        if ($entry->key === 'proxy.caddy_container_down') {
            $script = 'docker start '.escapeshellarg($caddyName);

            $this->remoteShell->run($node, $script, ['throw' => true]);

            return [
                'family' => 'proxy',
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'fix',
                'status' => 'completed',
                'summary' => "Started orbit-caddy container on {$node->name}.",
                'details' => [
                    'container' => $caddyName,
                ],
            ];
        }

        if ($entry->key === 'proxy.caddy_container_missing') {
            $spec = $this->managedCaddyContainerSpec($node);

            if ($spec === null) {
                return [
                    'family' => 'proxy',
                    'node' => $node->name,
                    'code' => $entry->key,
                    'key' => $entry->key,
                    'mode' => 'fix',
                    'status' => 'refused',
                    'summary' => "Refusing to recreate orbit-caddy on {$node->name}: node has no managed orbit-caddy tool record. Run node role baseline convergence first.",
                    'details' => [
                        'container' => $caddyName,
                        'reason' => 'no_managed_caddy_tool',
                    ],
                ];
            }

            $script = (new CaddyTool)->updateScript(['container' => $spec]);

            $this->remoteShell->run($node, $script, ['throw' => true]);

            return [
                'family' => 'proxy',
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'fix',
                'status' => 'completed',
                'summary' => "Reconciled orbit-caddy container on {$node->name}.",
                'details' => [
                    'container' => $caddyName,
                ],
            ];
        }

        return null;
    }

    /**
     * Resolve the node's managed orbit-caddy container spec. The role baseline
     * persists role-specific port bindings (public ingress vs private node vs
     * default) into NodeTool.config.container; reconcile must use that spec so
     * the recreated container matches what the role baseline expects.
     *
     * @return array<string, mixed>|null
     */
    private function managedCaddyContainerSpec(Node $node): ?array
    {
        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'caddy')
            ->first();

        if (! $tool instanceof NodeTool) {
            return null;
        }

        $config = is_array($tool->config) ? $tool->config : [];
        $container = $config['container'] ?? null;

        if (! is_array($container) || $container === []) {
            return null;
        }

        return $container;
    }

    /**
     * @return array<string, mixed>
     */
    public function removeExtra(Node $node, string $domain): array
    {
        $sitePath = "/etc/caddy/sites/{$domain}.caddy";
        $certPath = "/etc/orbit/certs/{$domain}.crt";
        $keyPath = "/etc/orbit/certs/{$domain}.key";

        $script = sprintf(
            <<<'SH'
sudo rm -f %s
sudo rm -f %s
sudo rm -f %s
%s || true
SH,
            escapeshellarg($sitePath),
            escapeshellarg($certPath),
            escapeshellarg($keyPath),
            $this->caddyReloadCommand($node),
        );

        $this->remoteShell->run($node, $script, ['throw' => true]);

        return [
            'family' => 'proxy',
            'node' => $node->name,
            'code' => $domain,
            'key' => $domain,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Removed extra proxy route {$domain} from node.",
        ];
    }

    private function caddyReloadCommand(Node $node): string
    {
        return CaddyTool::reloadCommand($this->caddyContainerName($node));
    }

    private function caddyContainerName(Node $node): string
    {
        $spec = $this->managedCaddyContainerSpec($node);
        $name = $spec['name'] ?? null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return (new OrbitContainerNames)->caddy();
    }

    private function validatedAbsolutePath(ProxyRoute $route, string $value, string $suffix): string
    {
        if (preg_match('/[\x00-\x1F\x7F\s]/', $value) === 1 || ! str_starts_with($value, '/')) {
            throw new \RuntimeException("Proxy route '{$route->domain}' {$suffix}");
        }

        return $value;
    }
}
