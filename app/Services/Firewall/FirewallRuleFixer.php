<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\FirewallRule;
use App\Services\Convergence\UfwFirewallRule;
use RuntimeException;

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

        return $this->convergeRule($rule, $entry);
    }

    public function remove(FirewallRule $rule): void
    {
        $rule->loadMissing('node');

        $convergence = UfwFirewallRule::fromRule($rule);

        $this->remoteShell->run($rule->node, $convergence->deleteCommand($convergence->expectedShape()), ['throw' => true]);
        $this->remoteShell->run($rule->node, $convergence->reloadCommand(), ['throw' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function convergeRule(FirewallRule $rule, DriftEntry $entry): array
    {
        $convergence = UfwFirewallRule::fromRule($rule);
        $probe = $convergence->probe($rule->node, $this->remoteShell);
        $plan = $convergence->plan($probe);
        $result = $convergence->apply($rule->node, $this->remoteShell, $plan);

        if ($result->status === ConvergenceStatus::Failed) {
            throw new RuntimeException((string) ($result->details['error'] ?? $result->summary));
        }

        if ($result->status === ConvergenceStatus::Unreachable) {
            throw new RuntimeException((string) ($result->details['error'] ?? $result->summary));
        }

        return [
            'family' => 'firewall_rule',
            'node' => $rule->node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => $result->changed() ? 'completed' : 'completed',
            'summary' => $result->summary,
            'details' => [
                'rule' => $rule->name,
                'convergence_status' => $result->status->value,
            ],
        ];
    }
}
