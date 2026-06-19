<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use InvalidArgumentException;

final class RemoteShellMetadata
{
    private const int MaxValueBytes = 4096;

    private const array ExactKeys = [
        'ORBIT_APP',
        'ORBIT_APP_PATH',
        'ORBIT_NODE_ID',
        'ORBIT_OPERATION_ID',
        'ORBIT_PHP_VERSION',
        'ORBIT_PROXY_DOMAIN',
        'ORBIT_PROXY_SUFFIX',
        'ORBIT_RELEASE_PATH',
        'ORBIT_REQUEST_ID',
        'ORBIT_TOOL_BINARY',
        'ORBIT_TOOL_CONFIG_PATH',
        'ORBIT_TOOL_SERVICE',
        'ORBIT_URL',
        'ORBIT_WG_EASY_DB_PATH',
        'ORBIT_WORKSPACE_BASE',
        'ORBIT_WORKSPACE_NAME',
        'ORBIT_WORKSPACE_PATH',
        'VITE_APP_URL',
        'VITE_VALET_HOST',
    ];

    private const array Prefixes = [
        'ORBIT_DEPLOY_',
        'ORBIT_POLYSCOPE_',
    ];

    /**
     * @param  array<string, string>  $metadata
     */
    public function prologue(array $metadata): string
    {
        $exports = [];

        foreach ($metadata as $key => $value) {
            $this->validate($key, $value);
            $exports[] = 'export '.$key.'='.escapeshellarg($value);
        }

        if ($exports === []) {
            return '';
        }

        return implode('; ', $exports).'; ';
    }

    public function validate(string $key, string $value): void
    {
        if (! $this->keyAllowed($key)) {
            throw new InvalidArgumentException("Remote shell metadata key [{$key}] is not allowed.");
        }

        if (strlen($value) > self::MaxValueBytes) {
            throw new InvalidArgumentException("Remote shell metadata value [{$key}] exceeds 4096 bytes.");
        }

        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException("Remote shell metadata value [{$key}] contains a NUL byte.");
        }

        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgumentException("Remote shell metadata value [{$key}] must be single-line.");
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException("Remote shell metadata value [{$key}] must be valid UTF-8.");
        }
    }

    private function keyAllowed(string $key): bool
    {
        if (in_array($key, self::ExactKeys, true)) {
            return true;
        }

        return array_any(self::Prefixes, fn (string $prefix): bool => str_starts_with($key, $prefix) && preg_match('/\A[A-Z0-9_]+\z/', $key) === 1);
    }
}
