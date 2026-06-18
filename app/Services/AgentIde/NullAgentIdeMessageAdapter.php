<?php

declare(strict_types=1);

namespace App\Services\AgentIde;

use App\Contracts\AgentIdeMessageAdapter;

final class NullAgentIdeMessageAdapter implements AgentIdeMessageAdapter
{
    public function activeSession(array $target, string $adapter): ?array
    {
        return null;
    }

    public function deliver(array $target, string $adapter, array $session, string $message): array
    {
        return [
            'status' => 'failed',
            'session' => $session,
        ];
    }

    public function workspaces(array $target, string $adapter): array
    {
        return [];
    }
}
