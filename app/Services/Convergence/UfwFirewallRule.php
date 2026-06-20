<?php

declare(strict_types=1);

namespace App\Services\Convergence;

use App\Contracts\RemoteShell;
use App\Data\Convergence\ConvergenceApplyResult;
use App\Data\Convergence\UfwFirewallRulePlan;
use App\Data\Convergence\UfwFirewallRuleProbe;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Firewall\FirewallRuleProbe;

final readonly class UfwFirewallRule
{
    public function __construct(
        public string $name,
        public string $direction,
        public string $action,
        public string $source,
        public ?string $destination,
        public string $port,
        public string $protocol,
        public string $addressFamily,
        public ?string $interface,
        public ?string $reason,
    ) {}

    public static function fromRule(FirewallRule $rule): self
    {
        $rule->loadMissing('node');

        return new self(
            name: $rule->name,
            direction: $rule->direction,
            action: $rule->action,
            source: $rule->source,
            destination: $rule->destination,
            port: (string) $rule->port,
            protocol: $rule->protocol,
            addressFamily: $rule->address_family,
            interface: $rule->interface,
            reason: $rule->reason,
        );
    }

    public function probe(Node $node, RemoteShell $remoteShell): UfwFirewallRuleProbe
    {
        [$snapshot, $error] = new FirewallRuleProbe($remoteShell)->tryIntrospectNode($node, $remoteShell);

        if (! $snapshot instanceof ProbeSnapshot) {
            return new UfwFirewallRuleProbe(
                reachable: false,
                present: false,
                error: $error,
            );
        }

        $expected = $this->expectedShape();

        if ($snapshot->get($this->identityKey($expected)) !== null) {
            return new UfwFirewallRuleProbe(
                reachable: true,
                present: true,
            );
        }

        $partialMatch = $this->findPartialShapeMatch($snapshot, $expected);

        return new UfwFirewallRuleProbe(
            reachable: true,
            present: false,
            partialMatch: $partialMatch,
        );
    }

    public function plan(UfwFirewallRuleProbe $probe): UfwFirewallRulePlan
    {
        if (! $probe->reachable) {
            return new UfwFirewallRulePlan(
                status: ConvergenceStatus::Unreachable,
                summary: "Could not inspect UFW rule {$this->name}.",
                details: $this->details(['error' => $probe->error]),
            );
        }

        if ($probe->present) {
            return new UfwFirewallRulePlan(
                status: ConvergenceStatus::Ok,
                summary: "UFW rule {$this->name} already matches gateway intent.",
                details: $this->details(),
            );
        }

        if ($probe->partialMatch !== null) {
            return new UfwFirewallRulePlan(
                status: ConvergenceStatus::Changed,
                summary: "Replace mismatched UFW rule {$this->name}.",
                details: $this->details([
                    'observed' => $probe->partialMatch,
                    'expected' => $this->expectedShape(),
                ]),
            );
        }

        return new UfwFirewallRulePlan(
            status: ConvergenceStatus::Changed,
            summary: "Apply missing UFW rule {$this->name}.",
            details: $this->details(['expected' => $this->expectedShape()]),
        );
    }

    public function apply(Node $node, RemoteShell $remoteShell, UfwFirewallRulePlan $plan): ConvergenceApplyResult
    {
        if (! $plan->shouldApply()) {
            return new ConvergenceApplyResult(
                status: $plan->status,
                summary: $plan->summary,
                details: $plan->details,
            );
        }

        $observed = is_array($plan->details['observed'] ?? null) ? $plan->details['observed'] : null;

        if ($observed !== null) {
            $deleteResult = $remoteShell->run($node, $this->deleteCommand($observed), ['throw' => false]);

            if (! $deleteResult->successful()) {
                return new ConvergenceApplyResult(
                    status: ConvergenceStatus::Failed,
                    summary: "Failed to delete mismatched UFW rule {$this->name}.",
                    details: $this->details([
                        'exit_code' => $deleteResult->exitCode,
                        'error' => trim($deleteResult->stderr) !== '' ? trim($deleteResult->stderr) : null,
                    ]),
                );
            }
        }

        $applyResult = $remoteShell->run($node, $this->applyCommand(), ['throw' => false]);

        if (! $applyResult->successful()) {
            return new ConvergenceApplyResult(
                status: ConvergenceStatus::Failed,
                summary: "Failed to apply UFW rule {$this->name}.",
                details: $this->details([
                    'exit_code' => $applyResult->exitCode,
                    'error' => trim($applyResult->stderr) !== '' ? trim($applyResult->stderr) : null,
                ]),
            );
        }

        $remoteShell->run($node, $this->reloadCommand(), ['throw' => false]);

        return new ConvergenceApplyResult(
            status: ConvergenceStatus::Changed,
            summary: "Applied UFW rule {$this->name}.",
            details: $this->details(),
        );
    }

    public function applyCommand(): string
    {
        $interface = $this->interfaceToken();

        $parts = [
            'sudo ufw',
            $this->action,
            $this->direction === 'outgoing' ? 'out' : 'in',
            ...$interface,
            'from',
            escapeshellarg($this->endpointForFamily($this->source, source: true)),
            'to',
            $this->destination === null ? $this->anyForFamily() : escapeshellarg($this->endpointForFamily($this->destination, source: false)),
            'port',
            escapeshellarg($this->port),
            'proto',
            escapeshellarg($this->protocol),
        ];

        if (is_string($this->reason) && $this->reason !== '') {
            $parts[] = 'comment';
            $parts[] = escapeshellarg($this->reason);
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $shape
     */
    public function deleteCommand(array $shape): string
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

    public function reloadCommand(): string
    {
        return 'sudo ufw reload';
    }

    /**
     * @return array<string, mixed>
     */
    public function expectedShape(): array
    {
        return [
            'direction' => $this->direction,
            'action' => $this->action,
            'source' => $this->normalizeAnyEndpoint($this->source),
            'destination' => $this->destination === null ? null : $this->normalizeAnyEndpoint($this->destination),
            'port' => $this->port,
            'protocol' => $this->protocol,
            'address_family' => $this->addressFamily,
            'interface' => $this->interface,
        ];
    }

    /**
     * @param  array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string, address_family?: string, interface?: ?string}  $expected
     * @return array<string, mixed>|null
     */
    private function findPartialShapeMatch(ProbeSnapshot $snapshot, array $expected): ?array
    {
        foreach ($snapshot->items as $observed) {
            if (($observed['inspected'] ?? false) === true) {
                continue;
            }

            if (
                ($observed['direction'] ?? null) === $expected['direction']
                && ($observed['action'] ?? null) === $expected['action']
                && ($observed['port'] ?? null) === $expected['port']
                && ($observed['protocol'] ?? null) === $expected['protocol']
                && (($expected['address_family'] ?? 'both') === 'both' || ($observed['address_family'] ?? 'both') === ($expected['address_family'] ?? 'both'))
            ) {
                return $observed;
            }
        }

        return null;
    }

    /**
     * @param  array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string, address_family?: string, interface?: ?string}  $shape
     */
    private function identityKey(array $shape): string
    {
        return implode(':', [
            $shape['direction'],
            $shape['action'],
            $shape['source'],
            $shape['destination'] ?? 'any',
            $shape['port'],
            $shape['protocol'],
            $shape['address_family'] ?? 'both',
            $shape['interface'] ?? 'any',
        ]);
    }

    /**
     * @return list<string>
     */
    private function interfaceToken(): array
    {
        if (! is_string($this->interface) || $this->interface === '') {
            return [];
        }

        return ['on', '$('.$this->interfaceResolver($this->interface).')'];
    }

    private function interfaceResolver(string $interface): string
    {
        if ($interface === 'wireguard') {
            return "ip -o link show type wireguard 2>/dev/null | awk -F': ' '{print \$2; exit}'";
        }

        return "ip route show default 0.0.0.0/0 2>/dev/null | awk '{print \$5; exit}'";
    }

    private function endpointForFamily(string $endpoint, bool $source): string
    {
        if ($endpoint !== 'any') {
            return $endpoint;
        }

        if ($this->addressFamily === 'v4') {
            return '0.0.0.0/0';
        }

        if ($this->addressFamily === 'v6') {
            return '::/0';
        }

        return $source ? 'any' : 'any';
    }

    private function anyForFamily(): string
    {
        if ($this->addressFamily === 'v4') {
            return '0.0.0.0/0';
        }

        if ($this->addressFamily === 'v6') {
            return '::/0';
        }

        return 'any';
    }

    private function normalizeAnyEndpoint(string $value): string
    {
        return match ($value) {
            '0.0.0.0/0', '::/0' => 'any',
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function details(array $extra = []): array
    {
        return [
            'rule' => $this->name,
            'direction' => $this->direction,
            'action' => $this->action,
            'source' => $this->source,
            'destination' => $this->destination,
            'port' => $this->port,
            'protocol' => $this->protocol,
            'address_family' => $this->addressFamily,
            'interface' => $this->interface,
            ...array_filter($extra, fn (mixed $value): bool => $value !== null),
        ];
    }
}
