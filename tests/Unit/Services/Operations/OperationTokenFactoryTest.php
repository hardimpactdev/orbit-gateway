<?php

declare(strict_types=1);

use App\Services\Operations\OperationTokenFactory;
use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Security\OperationTokenVerifier;
use Tests\TestCase;

uses(TestCase::class);

describe(OperationTokenFactory::class, function (): void {
    it('mints signed operation tokens with operation metadata and the configured ttl', function (): void {
        $issuedAt = 1_798_105_200;

        $token = operationTokenFactoryTestFactory(now: $issuedAt)->mint(
            operationId: 'operation-verify-1',
            targetNode: 'app-dev',
            command: 'internal:executor:verify',
        );

        expect($token)->toBeInstanceOf(OperationToken::class)
            ->and($token->id)->toBe('operation-verify-1')
            ->and($token->node)->toBe('app-dev')
            ->and($token->command)->toBe('internal:executor:verify')
            ->and($token->issuedAt)->toBe($issuedAt)
            ->and($token->expiresAt)->toBe($issuedAt + 120)
            ->and($token->signature)->not->toBe('')
            ->and(explode('.', $token->toString()))->toHaveCount(6)
            ->and(OperationToken::parse($token->toString()))->toEqual($token)
            ->and(operationTokenFactoryTestVerifier()->verify(
                secret: 'gateway-secret',
                token: $token,
                expectedNode: 'app-dev',
                expectedCommand: 'internal:executor:verify',
                now: $issuedAt,
            ))->toBeTrue();
    });

    it('mints tokens that fail verification for the wrong command', function (): void {
        $token = operationTokenFactoryTestFactory()->mint(
            operationId: 'operation-verify-1',
            targetNode: 'app-dev',
            command: 'internal:executor:verify',
        );

        expect(operationTokenFactoryTestVerifier()->verify(
            secret: 'gateway-secret',
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:workspace-adapter',
            now: 1_798_105_200,
        ))->toBeFalse();
    });

    it('mints tokens that fail verification for the wrong target node', function (): void {
        $token = operationTokenFactoryTestFactory()->mint(
            operationId: 'operation-verify-1',
            targetNode: 'app-dev',
            command: 'internal:executor:verify',
        );

        expect(operationTokenFactoryTestVerifier()->verify(
            secret: 'gateway-secret',
            token: $token,
            expectedNode: 'app-prod',
            expectedCommand: 'internal:executor:verify',
            now: 1_798_105_200,
        ))->toBeFalse();
    });

    it('mints short-lived tokens that fail verification after expiry', function (): void {
        $token = operationTokenFactoryTestFactory(ttlSeconds: 1, now: 1_798_105_200)->mint(
            operationId: 'operation-verify-1',
            targetNode: 'app-dev',
            command: 'internal:executor:verify',
        );

        expect(operationTokenFactoryTestVerifier()->verify(
            secret: 'gateway-secret',
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
            now: 1_798_105_202,
        ))->toBeFalse();
    });

    it('mints tokens that fail verification when payload bytes are modified', function (): void {
        $token = operationTokenFactoryTestFactory()->mint(
            operationId: 'operation-verify-1',
            targetNode: 'app-dev',
            command: 'internal:executor:verify',
        );

        $segments = explode('.', $token->toString());
        $segments[0] = operationTokenFactoryTestBase64UrlEncode('operation-verify-tampered');

        $tampered = OperationToken::parse(implode('.', $segments));

        expect($tampered->id)->toBe('operation-verify-tampered')
            ->and(operationTokenFactoryTestVerifier()->verify(
                secret: 'gateway-secret',
                token: $tampered,
                expectedNode: 'app-dev',
                expectedCommand: 'internal:executor:verify',
                now: 1_798_105_200,
            ))->toBeFalse();
    });

    it('throws instead of signing when the secret is empty', function (string $secret): void {
        expect(fn (): OperationTokenFactory => operationTokenFactoryTestFactory(secret: $secret))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'empty' => '',
        'blank' => '   ',
    ]);

    it('throws when the configured app key is missing', function (): void {
        config()->set('app.key', null);
        config()->set('orbit.operation_token_ttl_seconds', 120);

        app()->forgetInstance(OperationTokenFactory::class);

        expect(fn (): OperationTokenFactory => app(OperationTokenFactory::class))
            ->toThrow(RuntimeException::class);
    });

    it('resolves from the app key config through the container', function (): void {
        config()->set('app.key', 'gateway-app-key');
        config()->set('orbit.operation_token_ttl_seconds', '30');

        app()->forgetInstance(OperationTokenFactory::class);

        $factory = app(OperationTokenFactory::class);
        $token = $factory->mint(
            operationId: 'operation-verify-1',
            targetNode: 'app-dev',
            command: 'internal:executor:verify',
        );

        expect($token->expiresAt - $token->issuedAt)->toBe(30)
            ->and(operationTokenFactoryTestVerifier()->verify(
                secret: 'gateway-app-key',
                token: $token,
                expectedNode: 'app-dev',
                expectedCommand: 'internal:executor:verify',
                now: $token->issuedAt,
            ))->toBeTrue();
    });
});

function operationTokenFactoryTestFactory(
    string $secret = 'gateway-secret',
    int $ttlSeconds = 120,
    int $now = 1_798_105_200,
): OperationTokenFactory {
    return new OperationTokenFactory(
        signer: new OperationTokenSigner,
        secret: $secret,
        ttlSeconds: $ttlSeconds,
        clock: static fn (): int => $now,
    );
}

function operationTokenFactoryTestVerifier(): OperationTokenVerifier
{
    return new OperationTokenVerifier(new OperationTokenSigner);
}

function operationTokenFactoryTestBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
