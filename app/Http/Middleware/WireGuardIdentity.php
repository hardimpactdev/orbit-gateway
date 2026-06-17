<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class WireGuardIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof Node) {
            $request->setUserResolver(static fn (): Node => $user);

            return $next($request);
        }

        $node = Node::query()
            ->where('wireguard_address', $this->peerAddress($request))
            ->where('status', NodeStatus::Active->value)
            ->first();

        if (! $node instanceof Node) {
            return $this->forbidden();
        }

        $request->setUserResolver(static fn (): Node => $node);

        return $next($request);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => 'Peer identity unknown.',
                'meta' => [],
            ],
        ], 403);
    }

    private function peerAddress(Request $request): string
    {
        if ((bool) config('orbit.e2e_trust_wireguard_header', false)) {
            $header = $request->headers->get('X-Orbit-E2E-WireGuard-Ip');

            if (is_string($header) && filter_var($header, FILTER_VALIDATE_IP) !== false) {
                return $header;
            }
        }

        if ((bool) config('orbit.trust_wireguard_proxy_header', false)) {
            $header = $request->headers->get('X-Orbit-WireGuard-Ip');

            if (is_string($header) && filter_var($header, FILTER_VALIDATE_IP) !== false) {
                return $header;
            }
        }

        $peerAddress = (string) $request->ip();

        if ((bool) config('orbit.e2e_trust_wireguard_header', false)
            && config('orbit.e2e_topology_provider') === 'docker') {
            return $this->dockerTopologyPeerAddress($peerAddress) ?? $peerAddress;
        }

        return $peerAddress;
    }

    private function dockerTopologyPeerAddress(string $peerAddress): ?string
    {
        if (preg_match('/^10\.\d+\.0\.(?<host>[2-9])$/', $peerAddress, $matches) !== 1) {
            return null;
        }

        return "10.6.0.{$matches['host']}";
    }
}
