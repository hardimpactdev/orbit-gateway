<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Models\FirewallRule;

final readonly class FirewallRuleFixer
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(FirewallRule $rule, DriftEntry $entry): ?array
    {
        if (! in_array($entry->key, ['firewall_rule.rule_missing', 'firewall_rule.rule_mismatch'], true)) {
            return null;
        }

        $rule->loadMissing('node');

        if ($entry->key === 'firewall_rule.rule_mismatch') {
            $observed = is_array($entry->detail['observed'] ?? null) ? $entry->detail['observed'] : null;

            if ($observed !== null) {
                $this->remoteShell->run($rule->node, $this->deleteCommand($observed), ['throw' => false]);
            }
        }

        $this->remoteShell->run($rule->node, $this->applyCommand($rule), ['throw' => true]);
        $this->remoteShell->run($rule->node, 'sudo ufw reload', ['throw' => false]);

        return [
            'family' => 'firewall_rule',
            'node' => $rule->node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-applied firewall rule {$rule->name} from gateway intent.",
            'details' => [
                'rule' => $rule->name,
            ],
        ];
    }

    public function remove(FirewallRule $rule): void
    {
        $rule->loadMissing('node');

        $this->remoteShell->run($rule->node, $this->deleteCommand($this->expectedShape($rule)), ['throw' => true]);
        $this->remoteShell->run($rule->node, 'sudo ufw reload', ['throw' => false]);
    }

    private function applyCommand(FirewallRule $rule): string
    {
        $interface = $this->interfaceToken($rule);

        $parts = [
            'sudo ufw',
            $rule->action,
            $rule->direction === 'outgoing' ? 'out' : 'in',
            ...$interface,
            'from',
            escapeshellarg($this->endpointForFamily((string) $rule->source, (string) $rule->address_family, source: true)),
            'to',
            $rule->destination === null ? $this->anyForFamily((string) $rule->address_family) : escapeshellarg($this->endpointForFamily($rule->destination, (string) $rule->address_family, source: false)),
            'port',
            escapeshellarg((string) $rule->port),
            'proto',
            escapeshellarg($rule->protocol),
        ];

        if (is_string($rule->reason) && $rule->reason !== '') {
            $parts[] = 'comment';
            $parts[] = escapeshellarg($rule->reason);
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $shape
     */
    private function deleteCommand(array $shape): string
    {
        $interface = is_string($shape['interface'] ?? null) && $shape['interface'] !== ''
            ? ['on', '$('.$this->interfaceResolver((string) $shape['interface']).')']
            : [];

        $parts = [
            'sudo ufw delete',
            (string) ($shape['action'] ?? 'allow'),
            ($shape['direction'] ?? 'incoming') === 'outgoing' ? 'out' : 'in',
            ...$interface,
            'from',
            escapeshellarg((string) ($shape['source'] ?? 'any')),
            'to',
            is_string($shape['destination'] ?? null) ? escapeshellarg((string) $shape['destination']) : 'any',
            'port',
            escapeshellarg((string) ($shape['port'] ?? '*')),
            'proto',
            escapeshellarg((string) ($shape['protocol'] ?? 'tcp')),
        ];

        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedShape(FirewallRule $rule): array
    {
        return [
            'direction' => $rule->direction,
            'action' => $rule->action,
            'source' => $this->endpointForFamily($rule->source, $rule->address_family, source: true),
            'destination' => $rule->destination === null ? null : $this->endpointForFamily($rule->destination, $rule->address_family, source: false),
            'port' => (string) $rule->port,
            'protocol' => $rule->protocol,
            'address_family' => $rule->address_family,
            'interface' => $rule->interface,
        ];
    }

    /**
     * @return list<string>
     */
    private function interfaceToken(FirewallRule $rule): array
    {
        if (! is_string($rule->interface) || $rule->interface === '') {
            return [];
        }

        return ['on', '$('.$this->interfaceResolver($rule->interface).')'];
    }

    private function interfaceResolver(string $interface): string
    {
        if ($interface === 'wireguard') {
            return "ip -o link show type wireguard 2>/dev/null | awk -F': ' '{print \$2; exit}'";
        }

        return "ip route show default 0.0.0.0/0 2>/dev/null | awk '{print \$5; exit}'";
    }

    private function endpointForFamily(string $endpoint, string $addressFamily, bool $source): string
    {
        if ($endpoint !== 'any') {
            return $endpoint;
        }

        if ($addressFamily === 'v4') {
            return '0.0.0.0/0';
        }

        if ($addressFamily === 'v6') {
            return '::/0';
        }

        return $source ? 'any' : 'any';
    }

    private function anyForFamily(string $addressFamily): string
    {
        if ($addressFamily === 'v4') {
            return '0.0.0.0/0';
        }

        if ($addressFamily === 'v6') {
            return '::/0';
        }

        return 'any';
    }
}
