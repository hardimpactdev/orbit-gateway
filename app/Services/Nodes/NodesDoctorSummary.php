<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Doctor\DriftEntry;
use App\Models\Node;

final readonly class NodesDoctorSummary
{
    public function __construct(
        private NodesProbe $probe,
    ) {}

    /**
     * @param  iterable<Node>  $nodes
     * @return array{
     *     checked: int,
     *     issues: int,
     *     failures?: list<array{
     *         node: string,
     *         code: string,
     *         message: string,
     *         family: string,
     *         next_command: string,
     *     }>,
     * }
     */
    public function forNodes(iterable $nodes): array
    {
        $checked = 0;
        $failures = [];

        foreach ($nodes as $node) {
            $checked++;

            $snapshot = $this->probe->introspect($node);

            foreach ($this->probe->diff($node, $snapshot) as $entry) {
                $failures[] = $this->failureFor($node, $entry);
            }
        }

        $summary = [
            'checked' => $checked,
            'issues' => count($failures),
        ];

        if ($failures !== []) {
            $summary['failures'] = $failures;
        }

        return $summary;
    }

    /**
     * @return array{
     *     node: string,
     *     code: string,
     *     message: string,
     *     family: string,
     *     next_command: string,
     * }
     */
    private function failureFor(Node $node, DriftEntry $entry): array
    {
        return [
            'node' => (string) $node->name,
            'code' => $entry->key,
            'message' => $entry->summary,
            'family' => 'node',
            'next_command' => $this->nextCommandFor($node, $entry),
        ];
    }

    private function nextCommandFor(Node $node, DriftEntry $entry): string
    {
        if (in_array($entry->key, $this->restorableNodeIssueKeys(), true)) {
            return "doctor --restore --family=node --node={$node->name}";
        }

        return "doctor --family=node --node={$node->name}";
    }

    /**
     * @return list<string>
     */
    private function restorableNodeIssueKeys(): array
    {
        return [
            'node.role_convergence_failed',
            'node.role_baseline_mismatch',
        ];
    }
}
