<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Models\Node;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;

final class DnsmasqConfigBuilder
{
    /**
     * @param  Enumerable<int, Node>|iterable<int, Node>  $nodes
     */
    public function build(iterable $nodes): string
    {
        $resolvable = Collection::make($nodes)
            ->filter(fn (Node $node): bool => $this->isResolvable($node))
            ->sortBy(fn (Node $node): string => (string) $node->tld)
            ->values();

        $lines = [];

        foreach ($resolvable as $node) {
            $tld = (string) $node->tld;
            $address = (string) $node->wireguard_address;
            $lines[] = "address=/{$tld}/{$address}";
            $lines[] = "local=/{$tld}/";
        }

        $lines[] = 'no-resolv';
        $lines[] = 'server=1.1.1.1';
        $lines[] = 'server=8.8.8.8';
        $lines[] = 'conf-dir=/etc/dnsmasq.d/,*.conf';
        $lines[] = 'log-queries';
        $lines[] = 'log-facility=-';

        return implode("\n", $lines)."\n";
    }

    private function isResolvable(Node $node): bool
    {
        $tld = $node->tld;
        $address = $node->wireguard_address;

        return is_string($tld) && $tld !== ''
            && is_string($address) && $address !== '';
    }
}
