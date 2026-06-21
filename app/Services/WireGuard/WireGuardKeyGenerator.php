<?php

declare(strict_types=1);

namespace App\Services\WireGuard;

use RuntimeException;

final class WireGuardKeyGenerator
{
    /**
     * @return array{private_key: string, public_key: string}
     */
    public function generateKeyPair(): array
    {
        if (! function_exists('sodium_crypto_scalarmult_base')) {
            throw new RuntimeException('The sodium extension is required to generate WireGuard keys.');
        }

        $privateKey = random_bytes(SODIUM_CRYPTO_SCALARMULT_SCALARBYTES);
        $privateKey[0] = chr(ord($privateKey[0]) & 248);
        $privateKey[31] = chr((ord($privateKey[31]) & 127) | 64);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        return [
            'private_key' => base64_encode($privateKey),
            'public_key' => base64_encode($publicKey),
        ];
    }
}
