<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\AgentIde;

final readonly class AgentIdeAdapterChoicesResponse
{
    /**
     * @param  list<string>  $reservedTokens
     * @param  list<array<string, mixed>>  $adapters
     */
    public function __construct(
        public string $scope,
        public array $reservedTokens,
        public array $adapters,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedInputs(): array
    {
        return [
            ...$this->reservedTokens,
            ...array_values(array_filter(
                array_map(
                    static fn (array $adapter): mixed => $adapter['name'] ?? null,
                    $this->adapters,
                ),
                static fn (mixed $name): bool => is_string($name) && $name !== '',
            )),
        ];
    }
}
