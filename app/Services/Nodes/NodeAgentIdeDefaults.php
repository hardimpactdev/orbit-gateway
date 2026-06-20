<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;
use App\Services\AgentIde\AgentIdeAdapterRegistry;

final readonly class NodeAgentIdeDefaults
{
    public function __construct(
        private AgentIdeAdapterRegistry $registry,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     agent_ide: array{adapter: string|null, source: string},
     *     action: string
     * }
     */
    public function set(Node $node, string $adapter): array
    {
        $normalizedAdapter = $adapter === 'none' ? null : $adapter;
        $currentAdapter = $this->currentAdapter($node);
        $action = $currentAdapter === $normalizedAdapter ? 'converged' : 'set';

        if ($action === 'set') {
            $config = is_array($node->agent_ide_config) ? $node->agent_ide_config : [];

            if ($normalizedAdapter === null) {
                unset($config['adapter']);
            } else {
                $config['adapter'] = $normalizedAdapter;
            }

            $node->agent_ide_config = $config === [] ? null : $config;
            $node->save();
        }

        return [
            'name' => $node->name,
            'agent_ide' => self::payloadFor($node),
            'action' => $action,
        ];
    }

    public function isSupported(string $adapter): bool
    {
        return in_array($adapter, $this->supportedAdapters(), true);
    }

    /**
     * @return list<string>
     */
    public function supportedAdapters(): array
    {
        return $this->registry->supportedInputsForScope('node');
    }

    /**
     * @return array{adapter: string|null, source: string}
     */
    public static function payloadFor(Node $node): array
    {
        $adapter = self::adapterFromConfig($node->agent_ide_config);

        if ($adapter === null) {
            return [
                'adapter' => null,
                'source' => 'default',
            ];
        }

        return [
            'adapter' => $adapter,
            'source' => 'node',
        ];
    }

    private function currentAdapter(Node $node): ?string
    {
        return self::adapterFromConfig($node->agent_ide_config);
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    private static function adapterFromConfig(?array $config): ?string
    {
        $adapter = $config['adapter'] ?? null;

        return is_string($adapter) && $adapter !== '' ? $adapter : null;
    }
}
