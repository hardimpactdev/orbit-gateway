<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Closure;
use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenVerifier;

final readonly class OperationTokenIntrospector
{
    private Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock
     */
    public function __construct(
        private OperationTokenVerifier $verifier,
        private string $secret,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * @return array{allowed: bool, reason: ?string, operation_id: ?string}
     */
    public function introspect(string $compactToken, string $expectedNode, string $expectedCommand): array
    {
        try {
            $token = OperationToken::parse($compactToken);
        } catch (\InvalidArgumentException) {
            return $this->denied('invalid_token');
        }

        if (! hash_equals($expectedNode, $token->node)) {
            return $this->denied('target_node_mismatch', $token->id);
        }

        if (! hash_equals($expectedCommand, $token->command)) {
            return $this->denied('command_mismatch', $token->id);
        }

        if (! $this->verifier->verify(
            secret: $this->secret,
            token: $token,
            expectedNode: $expectedNode,
            expectedCommand: $expectedCommand,
            now: ($this->clock)(),
        )) {
            return $this->denied('invalid_token', $token->id);
        }

        return [
            'allowed' => true,
            'reason' => null,
            'operation_id' => $token->id,
        ];
    }

    /**
     * @return array{allowed: false, reason: string, operation_id: ?string}
     */
    private function denied(string $reason, ?string $operationId = null): array
    {
        return [
            'allowed' => false,
            'reason' => $reason,
            'operation_id' => $operationId,
        ];
    }
}
