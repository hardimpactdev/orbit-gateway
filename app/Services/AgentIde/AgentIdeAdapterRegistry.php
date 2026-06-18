<?php

declare(strict_types=1);

namespace App\Services\AgentIde;

final readonly class AgentIdeAdapterRegistry
{
    /**
     * @return list<array{name: string, label: string, source: string, capabilities: list<string>}>
     */
    public function adapters(): array
    {
        return [
            [
                'name' => 'opencode',
                'label' => 'opencode',
                'source' => 'core',
                'capabilities' => ['message_delivery', 'workspace_path_resolution'],
            ],
            [
                'name' => 'polyscope',
                'label' => 'polyscope',
                'source' => 'core',
                'capabilities' => ['message_delivery', 'workspace_path_resolution'],
            ],
        ];
    }

    public function isRegisteredAdapter(string $adapter): bool
    {
        return in_array($adapter, $this->adapterNames(), true);
    }

    /**
     * @return list<string>
     */
    public function adapterNames(): array
    {
        return array_map(
            static fn (array $adapter): string => $adapter['name'],
            $this->adapters(),
        );
    }

    /**
     * @return array{reserved_tokens: list<string>, adapters: list<array{name: string, label: string, source: string, capabilities: list<string>}>}
     */
    public function choicesForScope(string $scope): array
    {
        return [
            'reserved_tokens' => $this->reservedTokensForScope($scope),
            'adapters' => $this->adapters(),
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedInputsForScope(string $scope): array
    {
        return [
            ...$this->reservedTokensForScope($scope),
            ...$this->adapterNames(),
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedScopes(): array
    {
        return ['node', 'app'];
    }

    public function isSupportedScope(string $scope): bool
    {
        return in_array($scope, $this->supportedScopes(), true);
    }

    /**
     * @return list<string>
     */
    private function reservedTokensForScope(string $scope): array
    {
        return match ($scope) {
            'node' => ['none'],
            'app' => ['inherit', 'none'],
            default => [],
        };
    }
}
