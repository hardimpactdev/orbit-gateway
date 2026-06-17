<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use App\Enums\Nodes\NodeConvergenceContext;

final readonly class NodeConvergenceResult
{
    /**
     * @param  list<string>  $families
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array<string, mixed>>  $remainingIssues
     */
    public function __construct(
        public NodeConvergenceContext $context,
        public array $families,
        private array $issues,
        private array $actions,
        private array $remainingIssues,
    ) {}

    public function successful(): bool
    {
        return $this->remainingIssues === [] && $this->failedActions() === [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function remainingIssues(): array
    {
        return $this->remainingIssues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function failedActions(): array
    {
        return array_values(array_filter(
            $this->actions,
            fn (array $action): bool => ($action['status'] ?? null) === 'failed',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'context' => $this->context->value,
            'families' => $this->families,
            'successful' => $this->successful(),
            'issues' => $this->issues,
            'actions' => $this->actions,
            'remaining_issues' => $this->remainingIssues,
        ];
    }
}
