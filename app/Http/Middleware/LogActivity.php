<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Loggable;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

final readonly class LogActivity
{
    public function __construct(
        private ActivityLogCorrelation $correlation,
        private ActivityLogger $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $incomingUuid = $request->header('X-Orbit-Request-Id');
        $ownsCorrelation = $this->correlation->current() === null;

        if ($ownsCorrelation) {
            $this->correlation->start(is_string($incomingUuid) && $incomingUuid !== '' ? $incomingUuid : null);
        }

        try {
            $response = $next($request);
        } finally {
            $loggable = $this->resolveLoggable($request);

            if ($loggable instanceof Loggable) {
                $causer = $request->user();
                $this->logger->log(
                    $loggable,
                    channel: 'api',
                    causer: $causer instanceof Node ? $causer : null,
                    extraProperties: $this->extraProperties($request),
                );
            }

            if ($ownsCorrelation) {
                $this->correlation->end();
            }
        }

        return $response;
    }

    private function resolveLoggable(Request $request): ?Loggable
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        $controller = $route->getController();

        return $controller instanceof Loggable ? $controller : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function extraProperties(Request $request): array
    {
        $client = $request->header('X-Orbit-Client');
        $localNode = $this->resolveLocalNode();

        $servedByName = $localNode instanceof Node ? $localNode->name : gethostname();

        $props = [
            'client' => is_string($client) && $client !== '' ? $client : 'api',
            'method' => $request->method(),
            'path' => $request->path(),
            'served_by_name' => $servedByName ?: 'unknown',
            'served_by_wg_ip' => $localNode?->wireguard_address,
        ];

        foreach (['target_node', 'target_wg_ip', 'denied', 'reason'] as $key) {
            $attr = "proxy.{$key}";
            if ($request->attributes->has($attr)) {
                $props[$key] = $request->attributes->get($attr);
            }
        }

        return $props;
    }

    private function resolveLocalNode(): ?Node
    {
        if (! Schema::hasTable('nodes') || ! Schema::hasTable('node_role')) {
            return null;
        }

        $node = app(NodeRoleAssignments::class)
            ->activeGatewayNodeQuery()
            ->orderBy('name')
            ->first();

        return $node instanceof Node ? $node : null;
    }
}
