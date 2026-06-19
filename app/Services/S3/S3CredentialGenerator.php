<?php

declare(strict_types=1);

namespace App\Services\S3;

/**
 * Generates service-level SeaweedFS credentials for the `seaweedfs` tool row.
 *
 * Access key ID: 20-character uppercase alphanumeric token, compatible with
 * S3 client expectations for access key identifiers.
 *
 * Secret access key: 43-character base64url string derived from 32 secure
 * random bytes (~256 bits of entropy), URL-safe and compatible with SeaweedFS
 * and S3 client libraries.
 */
final class S3CredentialGenerator
{
    private const string AccessKeyAlphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    private const int AccessKeyLength = 20;

    private const int SecretKeyBytes = 32;

    public function generate(): S3Credentials
    {
        return new S3Credentials(
            accessKeyId: $this->generateAccessKeyId(),
            secretAccessKey: $this->generateSecretAccessKey(),
        );
    }

    private function generateAccessKeyId(): string
    {
        $alphabet = self::AccessKeyAlphabet;
        $alphabetLength = strlen($alphabet);
        $key = '';

        for ($i = 0; $i < self::AccessKeyLength; $i++) {
            $key .= $alphabet[random_int(0, $alphabetLength - 1)];
        }

        return $key;
    }

    private function generateSecretAccessKey(): string
    {
        $bytes = random_bytes(self::SecretKeyBytes);

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
