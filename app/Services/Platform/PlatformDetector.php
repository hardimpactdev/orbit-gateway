<?php

declare(strict_types=1);

namespace App\Services\Platform;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class PlatformDetector
{
    public function detectLocal(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => $this->detectMacOs(),
            'Linux' => $this->detectLinux(),
            default => throw new RuntimeException("Unsupported platform family: {$this->normalizedFamily()}"),
        };
    }

    public function detectMacOs(): string
    {
        $result = Process::timeout(10)->run('sw_vers -productVersion');

        if (! $result->successful()) {
            throw new RuntimeException('Failed to detect macOS version.');
        }

        return $this->macOsIdentifier($result->output());
    }

    public function detectLinux(): string
    {
        $result = Process::timeout(10)->run('cat /etc/os-release');

        if (! $result->successful()) {
            throw new RuntimeException('Failed to read /etc/os-release.');
        }

        return $this->linuxIdentifier($result->output());
    }

    public function macOsIdentifier(string $version): string
    {
        $normalized = $this->normalizeVersion(trim($version));

        if ($normalized === '') {
            throw new RuntimeException('macOS version is empty.');
        }

        return "macos_{$normalized}";
    }

    public function linuxIdentifier(string $osRelease): string
    {
        $values = $this->parseOsRelease($osRelease);
        $id = $this->normalizeId($values['ID'] ?? '');
        $version = $this->normalizeVersion($values['VERSION_ID'] ?? '');

        if ($id === '' || $version === '') {
            throw new RuntimeException('Linux platform metadata is incomplete.');
        }

        return "{$id}_{$version}";
    }

    /**
     * @return array<string, string>
     */
    private function parseOsRelease(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);

            if ($key === '') {
                continue;
            }

            $values[$key] = trim(trim($value), "\"'");
        }

        return $values;
    }

    private function normalizeId(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', trim($value)));
    }

    private function normalizeVersion(string $value): string
    {
        return trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', trim($value)), '-');
    }

    private function normalizedFamily(): string
    {
        return PHP_OS_FAMILY;
    }
}
