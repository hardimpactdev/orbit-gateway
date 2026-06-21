<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;

final class DnsmasqConfigBuilder
{
    /**
     * @param  Enumerable<int, Node>|iterable<int, Node>  $nodes
     * @param  Enumerable<int, ProxyRoute>|iterable<int, ProxyRoute>  $serviceRoutes
     */
    public function build(iterable $nodes, iterable $serviceRoutes = []): string
    {
        $allNodes = Collection::make($nodes)->values();
        $resolvable = $allNodes
            ->filter(fn (Node $node): bool => $this->isResolvable($node))
            ->sortBy(fn (Node $node): string => (string) $node->tld)
            ->values();

        $routes = Collection::make($serviceRoutes)
            ->filter(fn (ProxyRoute $route): bool => $this->isResolvableServiceRoute($route, $allNodes))
            ->sortBy(fn (ProxyRoute $route): string => $route->domain)
            ->values();

        $lines = [];

        foreach ($resolvable as $node) {
            $tld = (string) $node->tld;
            $address = (string) $node->wireguard_address;
            $lines[] = "address=/{$tld}/{$address}";
            $lines[] = "local=/{$tld}/";

            if ($this->shouldEmitOrbitNodeHostRecord($tld)) {
                $lines[] = "address=/orbit.{$tld}/{$address}";
            }
        }

        $orbitRouters = $routes
            ->map(fn (ProxyRoute $route): ?Node => $this->routeNode($route, $allNodes))
            ->filter(fn (?Node $node): bool => $node instanceof Node)
            ->unique(fn (Node $node): string => (string) $node->wireguard_address)
            ->sortBy(fn (Node $node): string => (string) $node->wireguard_address)
            ->values();

        foreach ($orbitRouters as $router) {
            $address = (string) $router->wireguard_address;

            $lines[] = "address=/orbit/{$address}";
            $lines[] = 'local=/orbit/';
        }

        $lines[] = 'no-resolv';
        $lines[] = 'server=1.1.1.1';
        $lines[] = 'server=8.8.8.8';
        $lines[] = 'conf-dir=/etc/dnsmasq.d/,*.conf';
        $lines[] = 'log-queries';
        $lines[] = 'log-facility=-';

        return implode("\n", $lines)."\n";
    }

    public function buildGatewayState(): string
    {
        return $this->build(
            Node::query()->get(),
            ProxyRoute::query()->with('node')->get(),
        );
    }

    private function isResolvable(Node $node): bool
    {
        $tld = $node->tld;
        $address = $node->wireguard_address;

        return is_string($tld) && $tld !== ''
            && is_string($address) && $address !== '';
    }

    /**
     * @param  Collection<int, Node>  $nodes
     */
    private function isResolvableServiceRoute(ProxyRoute $route, Collection $nodes): bool
    {
        if ($route->owner_type !== 'router' || ! str_ends_with($route->domain, '.orbit')) {
            return false;
        }

        return $this->routeNode($route, $nodes) instanceof Node;
    }

    /**
     * @param  Collection<int, Node>  $nodes
     */
    private function routeNode(ProxyRoute $route, Collection $nodes): ?Node
    {
        $node = $route->relationLoaded('node') ? $route->node : null;

        if (! $node instanceof Node && is_int($route->node_id)) {
            $node = $nodes->first(fn (Node $candidate): bool => $candidate->id === $route->node_id);
        }

        if (! $node instanceof Node || ! $this->isAddressable($node)) {
            return null;
        }

        return $node;
    }

    private function isAddressable(Node $node): bool
    {
        $address = $node->wireguard_address;

        return is_string($address) && $address !== '';
    }

    private function shouldEmitOrbitNodeHostRecord(string $tld): bool
    {
        return $tld !== 'orbit';
    }
}
