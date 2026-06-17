<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Data\Security\PinnedHostKey;
use App\Models\Node;
use App\Services\RemoteShell\Exceptions\HostKeyMismatch;
use App\Services\RemoteShell\Exceptions\HostKeyPinningFailed;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

final readonly class SshHostKeyPinner
{
    private const array PreferredTypes = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ssh-rsa'];

    private const int ScanAttempts = 4;

    private const int ScanRetryDelayMicroseconds = 500_000;

    public function pin(string $host, ?string $expectedFingerprint = null): PinnedHostKey
    {
        $failureReason = 'ssh-keyscan failed';
        $key = null;

        for ($attempt = 1; $attempt <= self::ScanAttempts; $attempt++) {
            $result = Process::timeout(20)->run(sprintf(
                'ssh-keyscan -T 10 -t ed25519,ecdsa,rsa %s 2>/dev/null',
                escapeshellarg($host),
            ));

            if (trim($result->output()) !== '') {
                $key = $this->parsePreferredKey($host, $result->output());

                if ($key instanceof PinnedHostKey) {
                    break;
                }

                $failureReason = 'No supported SSH host key was returned by ssh-keyscan.';

                break;
            }

            $failureReason = trim($result->errorOutput())
                ?: ($result->successful() ? 'ssh-keyscan returned no host keys.' : 'ssh-keyscan failed');

            if ($attempt < self::ScanAttempts) {
                usleep(self::ScanRetryDelayMicroseconds);
            }
        }

        if (! $key instanceof PinnedHostKey) {
            $this->logPinEvent('node.host_key.pin_failed', $host, [
                'reason' => $failureReason,
            ]);

            throw HostKeyPinningFailed::forHost($host, $failureReason);
        }

        if ($expectedFingerprint !== null) {
            $expected = $this->normalizeFingerprint($expectedFingerprint);

            if (! hash_equals($expected, $key->fingerprint)) {
                $this->logPinEvent('node.host_key.mismatch', $host, [
                    'expected_fingerprint' => $expected,
                    'observed_fingerprint' => $key->fingerprint,
                ]);

                throw HostKeyMismatch::forHost($host, $expected, $key->fingerprint);
            }

            $this->logPinEvent('node.host_key.pinned_verified', $host, [
                'fingerprint' => $key->fingerprint,
                'type' => $key->type,
            ]);

            return new PinnedHostKey($key->host, $key->type, $key->publicKey, $key->fingerprint, 'verified');
        }

        $this->logPinEvent('node.host_key.pinned_tofu', $host, [
            'fingerprint' => $key->fingerprint,
            'type' => $key->type,
        ]);

        return $key;
    }

    public function persist(Node $node, PinnedHostKey $key): void
    {
        $node->forceFill([
            'host_key_type' => $key->type,
            'host_key_public' => $key->publicKey,
            'host_key_fingerprint' => $key->fingerprint,
            'host_key_pin_mode' => $key->pinMode,
            'host_key_pinned_at' => now(),
        ])->save();
    }

    public static function fingerprintForPublicKey(string $publicKey): string
    {
        $decoded = base64_decode($publicKey, true);

        if ($decoded === false) {
            throw HostKeyPinningFailed::forHost('unknown', 'Host public key is not valid base64.');
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $decoded, true)), '=');
    }

    private function parsePreferredKey(string $host, string $output): ?PinnedHostKey
    {
        $keys = [];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);

            if (! is_array($parts) || count($parts) < 3) {
                continue;
            }

            [$scannedHost, $type, $publicKey] = $parts;

            if (! in_array($type, self::PreferredTypes, true)) {
                continue;
            }

            $keys[$type] = new PinnedHostKey(
                host: $scannedHost !== '' ? $scannedHost : $host,
                type: $type,
                publicKey: $publicKey,
                fingerprint: self::fingerprintForPublicKey($publicKey),
                pinMode: 'tofu',
            );
        }

        foreach (self::PreferredTypes as $type) {
            if (isset($keys[$type])) {
                return $keys[$type];
            }
        }

        return null;
    }

    private function normalizeFingerprint(string $fingerprint): string
    {
        $fingerprint = trim($fingerprint);

        return str_starts_with($fingerprint, 'SHA256:')
            ? $fingerprint
            : "SHA256:{$fingerprint}";
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logPinEvent(string $event, string $host, array $properties): void
    {
        if (! Schema::hasTable('activity_log')) {
            return;
        }

        activity('security')
            ->event($event)
            ->withProperties([
                'type' => 'write',
                'host' => $host,
                ...$properties,
            ])
            ->log($event);
    }
}
