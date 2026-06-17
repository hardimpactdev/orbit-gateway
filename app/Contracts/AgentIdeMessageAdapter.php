<?php

declare(strict_types=1);

namespace App\Contracts;

interface AgentIdeMessageAdapter
{
    /**
     * @param  array{app: string, workspace: string|null, node: string}  $target
     * @return array{id: string|null, status: string}|null
     */
    public function activeSession(array $target, string $adapter): ?array;

    /**
     * @param  array{app: string, workspace: string|null, node: string}  $target
     * @param  array{id: string|null, status: string}  $session
     * @return array{status: string, session?: array{id: string|null, status: string}}
     */
    public function deliver(array $target, string $adapter, array $session, string $message): array;

    /**
     * Return the list of workspace names currently active for the app
     * according to the adapter's source of truth.
     *
     * @param  array{app: string, node: string}  $target
     * @return list<string>
     */
    public function workspaces(array $target, string $adapter): array;
}
