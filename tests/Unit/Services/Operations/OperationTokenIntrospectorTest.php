<?php

declare(strict_types=1);

use App\Services\Operations\OperationTokenIntrospector;
use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Security\OperationTokenVerifier;
use Tests\TestCase;

uses(TestCase::class);

describe(OperationTokenIntrospector::class, function (): void {
    it('allows a valid token for the authenticated node and command', function (): void {
        $token = operationTokenIntrospectorTestSigner()->sign(
            secret: 'gateway-secret',
            id: 'operation-123',
            node: 'app-dev',
            command: 'internal:executor:verify',
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        expect(operationTokenIntrospector()->introspect(
            compactToken: $token->toString(),
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
        ))->toBe([
            'allowed' => true,
            'reason' => null,
            'operation_id' => 'operation-123',
        ]);
    });

    it('rejects malformed tokens', function (): void {
        expect(operationTokenIntrospector()->introspect(
            compactToken: 'not-a-token',
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
        ))->toBe([
            'allowed' => false,
            'reason' => 'invalid_token',
            'operation_id' => null,
        ]);
    });

    it('rejects tokens for a different target node', function (): void {
        $token = operationTokenIntrospectorTestSigner()->sign(
            secret: 'gateway-secret',
            id: 'operation-123',
            node: 'app-prod',
            command: 'internal:executor:verify',
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        expect(operationTokenIntrospector()->introspect(
            compactToken: $token->toString(),
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
        ))->toBe([
            'allowed' => false,
            'reason' => 'target_node_mismatch',
            'operation_id' => 'operation-123',
        ]);
    });

    it('rejects tokens for a different command', function (): void {
        $token = operationTokenIntrospectorTestSigner()->sign(
            secret: 'gateway-secret',
            id: 'operation-123',
            node: 'app-dev',
            command: 'internal:executor:status',
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        expect(operationTokenIntrospector()->introspect(
            compactToken: $token->toString(),
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
        ))->toBe([
            'allowed' => false,
            'reason' => 'command_mismatch',
            'operation_id' => 'operation-123',
        ]);
    });

    it('rejects tokens with invalid signatures', function (): void {
        $token = operationTokenIntrospectorTestSigner()->sign(
            secret: 'gateway-secret',
            id: 'operation-123',
            node: 'app-dev',
            command: 'internal:executor:verify',
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        $segments = explode('.', $token->toString());
        $segments[0] = operationTokenIntrospectorBase64UrlEncode('operation-tampered');

        expect(operationTokenIntrospector()->introspect(
            compactToken: implode('.', $segments),
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
        ))->toBe([
            'allowed' => false,
            'reason' => 'invalid_token',
            'operation_id' => 'operation-tampered',
        ]);
    });

    it('rejects expired tokens', function (): void {
        $token = operationTokenIntrospectorTestSigner()->sign(
            secret: 'gateway-secret',
            id: 'operation-123',
            node: 'app-dev',
            command: 'internal:executor:verify',
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_201,
        );

        expect(operationTokenIntrospector(now: 1_798_105_202)->introspect(
            compactToken: $token->toString(),
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
        ))->toBe([
            'allowed' => false,
            'reason' => 'invalid_token',
            'operation_id' => 'operation-123',
        ]);
    });

    it('resolves from the app key config through the container', function (): void {
        config()->set('app.key', 'gateway-app-key');
        config()->set('orbit.operation_token_ttl_seconds', 120);

        app()->forgetInstance(OperationTokenIntrospector::class);

        $token = operationTokenIntrospectorTestSigner()->sign(
            secret: 'gateway-app-key',
            id: 'operation-123',
            node: 'app-dev',
            command: 'internal:executor:verify',
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        expect(app(OperationTokenIntrospector::class)->introspect(
            compactToken: $token->toString(),
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
        ))->toBe([
            'allowed' => true,
            'reason' => null,
            'operation_id' => 'operation-123',
        ]);
    });

    it('throws when the configured app key is missing', function (): void {
        config()->set('app.key', null);
        config()->set('orbit.operation_token_ttl_seconds', 120);

        app()->forgetInstance(OperationTokenIntrospector::class);

        expect(fn (): OperationTokenIntrospector => app(OperationTokenIntrospector::class))
            ->toThrow(RuntimeException::class);
    });
});

function operationTokenIntrospector(?int $now = 1_798_105_200): OperationTokenIntrospector
{
    return new OperationTokenIntrospector(
        verifier: new OperationTokenVerifier(new OperationTokenSigner),
        secret: 'gateway-secret',
        clock: $now === null ? null : static fn (): int => $now,
    );
}

function operationTokenIntrospectorTestSigner(): OperationTokenSigner
{
    return new OperationTokenSigner;
}

function operationTokenIntrospectorBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
