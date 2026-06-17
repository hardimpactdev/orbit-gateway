<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Closure;
use InvalidArgumentException;
use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenSigner;

final readonly class OperationTokenFactory
{
    private Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock
     */
    public function __construct(
        private OperationTokenSigner $signer,
        private string $secret,
        private int $ttlSeconds,
        ?Closure $clock = null,
    ) {
        if (trim($secret) === '') {
            throw new InvalidArgumentException('Operation token signing secret is required.');
        }

        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('Operation token TTL must be at least one second.');
        }

        $this->clock = $clock ?? time(...);
    }

    public function mint(string $operationId, string $targetNode, string $command): OperationToken
    {
        $this->ensurePayloadFieldPresent($operationId);
        $this->ensurePayloadFieldPresent($targetNode);
        $this->ensurePayloadFieldPresent($command);

        $issuedAt = ($this->clock)();

        if (! is_int($issuedAt) || $issuedAt < 0) {
            throw new InvalidArgumentException('Operation token clock returned an invalid timestamp.');
        }

        if ($issuedAt > PHP_INT_MAX - $this->ttlSeconds) {
            throw new InvalidArgumentException('Operation token expiry timestamp is invalid.');
        }

        return $this->signer->sign(
            secret: $this->secret,
            id: $operationId,
            node: $targetNode,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $issuedAt + $this->ttlSeconds,
        );
    }

    private function ensurePayloadFieldPresent(string $value): void
    {
        if (trim($value) !== '') {
            return;
        }

        throw new InvalidArgumentException('Operation token payload is incomplete.');
    }
}
