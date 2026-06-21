<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Node;

/**
 * Records the remote nodes a CLI command (or API controller) communicates
 * with so the resulting activity log entry can be stamped with
 * target_node/target_wg_ip. This lets the tail renderer produce
 * "<consumer> performed <action> on <target>" lines even when the log entry
 * itself is written locally (e.g. on a control node).
 *
 * Singleton per process; LogsCommandActivity::finalizeActivityLog() resets
 * it at the end of a command.
 */
final class ActivityLogTargets
{
    /** @var array<string, Node> */
    private array $nodes = [];

    public function add(Node $node): void
    {
        $key = (string) ($node->wireguard_address ?? $node->name ?? spl_object_hash($node));
        $this->nodes[$key] = $node;
    }

    public function primary(): ?Node
    {
        return $this->nodes === [] ? null : array_first($this->nodes);
    }

    public function reset(): void
    {
        $this->nodes = [];
    }
}
