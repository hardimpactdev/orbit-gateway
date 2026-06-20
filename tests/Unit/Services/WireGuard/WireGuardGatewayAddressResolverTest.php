<?php

declare(strict_types=1);

use App\Services\WireGuard\WireGuardGatewayAddressResolver;

it('derives the gateway address from one active orbit wireguard address', function (): void {
    $resolver = new WireGuardGatewayAddressResolver;

    expect($resolver->resolveFromAddresses(['127.0.0.1', '10.6.0.8/32']))
        ->toBe('10.6.0.2');
});

it('accepts multiple local addresses that resolve to the same gateway', function (): void {
    $resolver = new WireGuardGatewayAddressResolver;

    expect($resolver->resolveFromAddresses(['10.6.0.8', '10.6.0.9/32']))
        ->toBe('10.6.0.2');
});

it('returns null when gateway derivation is ambiguous', function (): void {
    $resolver = new WireGuardGatewayAddressResolver;

    expect($resolver->resolveFromAddresses(['10.6.0.8', '10.6.1.8']))
        ->toBeNull();
});

it('returns null when no orbit wireguard address is active', function (): void {
    $resolver = new WireGuardGatewayAddressResolver;

    expect($resolver->resolveFromAddresses(['192.168.1.10', '10.44.0.8', 'not-an-ip']))
        ->toBeNull();
});
