<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Contracts\RemoteShell;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Models\AppWebSocketBinding;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Collection;

class WebSocketRuntimeAppConfigSyncer
{
    public function __construct(
        private readonly RemoteShell $remoteShell,
        private readonly NodeRoleAssignments $nodeRoleAssignments,
        private readonly WebSocketRuntimeContainerRenderer $runtimeContainerRenderer,
    ) {}

    public function sync(): void
    {
        $content = $this->configFileContent($this->enabledBindings());

        foreach ($this->webSocketNodes() as $node) {
            $this->remoteShell->run($node, $this->installScript($node, $content), [
                'throw' => true,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'websocket-runtime-app-config-sync',
                ],
            ]);
        }
    }

    /**
     * @return Collection<int, AppWebSocketBinding>
     */
    private function enabledBindings(): Collection
    {
        return AppWebSocketBinding::query()
            ->with('app')
            ->where('enabled', true)
            ->orderBy('reverb_app_id')
            ->get();
    }

    /**
     * @return list<Node>
     */
    private function webSocketNodes(): array
    {
        /** @var list<Node> $nodes */
        $nodes = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::WebSocket->value))
            ->orderBy('name')
            ->get()
            ->all();

        return $nodes;
    }

    /**
     * @param  Collection<int, AppWebSocketBinding>  $bindings
     */
    private function configFileContent(Collection $bindings): string
    {
        $apps = $bindings
            ->map(fn (AppWebSocketBinding $binding): array => [
                'key' => $binding->reverb_app_key,
                'secret' => $binding->reverb_app_secret,
                'app_id' => $binding->reverb_app_id,
                'options' => [
                    'host' => WebSocketRouteRegistrar::ServiceDomain,
                    'port' => 443,
                    'scheme' => 'https',
                    'useTLS' => true,
                ],
                'allowed_origins' => $this->allowedOrigins($binding),
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_message_size' => 10_000,
            ])
            ->values()
            ->all();

        return "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($apps, true).";\n";
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(AppWebSocketBinding $binding): array
    {
        $origins = $binding->allowed_origins;

        if ($origins === []) {
            return ['*'];
        }

        $hosts = [];

        foreach ($origins as $origin) {
            if (! is_string($origin)) {
                continue;
            }

            $origin = trim($origin);

            if ($origin === '') {
                continue;
            }

            if ($origin === '*') {
                return ['*'];
            }

            $host = parse_url($origin, PHP_URL_HOST);
            $host = is_string($host) && $host !== '' ? $host : $origin;
            $host = strtolower(trim($host));

            if ($host !== '' && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts === [] ? ['*'] : $hosts;
    }

    private function installScript(Node $node, string $content): string
    {
        $containerName = $this->runtimeContainerRenderer->containerName($node);

        return sprintf(
            <<<'SH'
set -e
sudo install -d -m 0755 /etc/orbit/websocket
printf %%s %s | base64 -d | sudo tee %s >/dev/null
sudo chmod 0644 %s
if docker container inspect %s >/dev/null 2>&1; then
    docker restart %s >/dev/null
fi
SH,
            escapeshellarg(base64_encode($content)),
            escapeshellarg(WebSocketRuntimeSourceInstaller::AppsConfigPath),
            escapeshellarg(WebSocketRuntimeSourceInstaller::AppsConfigPath),
            escapeshellarg($containerName),
            escapeshellarg($containerName),
        );
    }
}
