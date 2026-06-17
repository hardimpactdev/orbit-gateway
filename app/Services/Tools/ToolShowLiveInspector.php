<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\Node;
use App\Models\NodeTool;
use RuntimeException;
use Throwable;

final readonly class ToolShowLiveInspector
{
    public function __construct(private ToolsProbe $probe) {}

    /**
     * @return array{observed_state: string|null, observed_version: string|null}
     *
     * @throws ToolShowLiveInspectionFailed
     */
    public function inspect(NodeTool $tool): array
    {
        try {
            $snapshot = $this->probe->introspect($tool);
        } catch (Throwable $e) {
            throw ToolShowLiveInspectionFailed::forTool($tool, 'probe_exception', $e->getMessage(), $e);
        }

        $observed = $snapshot->get($tool->name);

        if ($observed === null) {
            throw ToolShowLiveInspectionFailed::forTool($tool, 'empty_snapshot', 'Live probe returned no tool state.');
        }

        return [
            'observed_state' => $this->observedState($observed),
            'observed_version' => is_string($observed['version'] ?? null) && $observed['version'] !== ''
                ? $observed['version']
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $observed
     */
    private function observedState(array $observed): ?string
    {
        if (($observed['installed'] ?? null) === false) {
            return 'absent';
        }

        $state = $observed['state'] ?? null;

        if (is_string($state) && $state !== '' && $state !== 'unknown') {
            return $state;
        }

        if (($observed['installed'] ?? null) === true) {
            return 'installed';
        }

        return null;
    }
}

final class ToolShowLiveInspectionFailed extends RuntimeException
{
    private function __construct(string $message, public readonly string $tool, public readonly string $node, public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function forTool(NodeTool $tool, string $reason, string $message, ?Throwable $previous = null): self
    {
        $tool->loadMissing('node');

        return new self(
            message: $message !== '' ? $message : 'Live tool inspection failed.',
            tool: $tool->name,
            node: $tool->node instanceof Node ? $tool->node->name : '',
            reason: $reason,
            previous: $previous,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'tool' => $this->tool,
            'node' => $this->node,
            'reason' => $this->reason,
        ];
    }
}
