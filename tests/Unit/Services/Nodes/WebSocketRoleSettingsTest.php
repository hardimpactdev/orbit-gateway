<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;

it('requires a positive redis node id', function (): void {
    expect(WebSocketRoleSettings::fromArray(['redis_node_id' => 12])->toArray())
        ->toBe(['redis_node_id' => 12]);
});

it('rejects missing redis node id', function (): void {
    expect(fn () => WebSocketRoleSettings::fromArray([]))
        ->toThrow(InvalidArgumentException::class, 'The websocket role requires a valid redis_node_id setting.');
});

it('rejects non-positive redis node id values', function (mixed $redisNodeId): void {
    expect(fn () => WebSocketRoleSettings::fromArray(['redis_node_id' => $redisNodeId]))
        ->toThrow(InvalidArgumentException::class, 'The websocket role requires a valid redis_node_id setting.');
})->with([
    'zero' => 0,
    'negative' => -1,
    'string' => '12',
]);

it('rejects unknown settings', function (): void {
    expect(fn () => WebSocketRoleSettings::fromArray(['redis_node_id' => 12, 'host' => 'ws.example.com']))
        ->toThrow(InvalidArgumentException::class, 'The websocket role does not accept unknown settings.');
});
