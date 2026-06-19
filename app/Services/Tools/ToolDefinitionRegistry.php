<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\ToolDefinition;

final readonly class ToolDefinitionRegistry
{
    /**
     * @var array<string, ToolDefinition>
     */
    private array $definitions;

    /**
     * @param  iterable<ToolDefinition>  $definitions
     */
    public function __construct(iterable $definitions)
    {
        $indexed = [];

        foreach ($definitions as $definition) {
            $indexed[$definition->slug()] = $definition;
        }

        $this->definitions = $indexed;
    }

    /**
     * @return list<ToolDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function get(string $slug): ?ToolDefinition
    {
        return $this->definitions[$slug] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->definitions);
    }
}
