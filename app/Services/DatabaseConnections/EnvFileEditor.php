<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

final class EnvFileEditor
{
    /**
     * @return array<string, string>
     */
    public function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            $parsed = $this->parseAssignment($line);

            if ($parsed === null) {
                continue;
            }

            $values[$parsed['key']] = $parsed['value'];
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $updates
     */
    public function update(string $contents, array $updates): string
    {
        $lineEnding = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $hadTrailingLineEnding = $contents !== '' && preg_match("/(\r\n|\n|\r)\z/", $contents) === 1;

        if ($hadTrailingLineEnding && $lines !== []) {
            array_pop($lines);
        }

        if ($contents === '' && $lines === ['']) {
            $lines = [];
        }

        $remaining = $updates;
        $matchedKeys = [];

        foreach ($lines as $index => $line) {
            $parsed = $this->parseAssignment($line);

            if ($parsed === null) {
                continue;
            }

            $key = $parsed['key'];

            if (! array_key_exists($key, $remaining)) {
                continue;
            }

            $lines[$index] = sprintf('%s%s=%s', $parsed['prefix'], $key, $this->formatValue($remaining[$key]));
            $matchedKeys[$key] = true;
        }

        foreach (array_keys($matchedKeys) as $key) {
            unset($remaining[$key]);
        }

        foreach ($remaining as $key => $value) {
            $lines[] = sprintf('%s=%s', $key, $this->formatValue($value));
        }

        $updated = implode($lineEnding, $lines);

        if ($hadTrailingLineEnding) {
            return $updated.$lineEnding;
        }

        return $updated;
    }

    /**
     * @return array{prefix: string, key: string, value: string}|null
     */
    private function parseAssignment(string $line): ?array
    {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            return null;
        }

        $position = strpos($line, '=');

        if ($position === false) {
            return null;
        }

        $keyPart = trim(substr($line, 0, $position));
        $prefix = '';

        if (str_starts_with($keyPart, 'export ')) {
            $prefix = 'export ';
            $keyPart = trim(substr($keyPart, 7));
        }

        if ($keyPart === '') {
            return null;
        }

        $value = substr($line, $position + 1);

        return [
            'prefix' => $prefix,
            'key' => $keyPart,
            'value' => $this->parseValue($value),
        ];
    }

    private function parseValue(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        $quote = $trimmed[0];
        $last = substr($trimmed, -1);

        if (($quote === '"' || $quote === "'") && $last === $quote) {
            $inner = substr($trimmed, 1, -1);

            return $quote === '"'
                ? str_replace(['\\"', '\\\\'], ['"', '\\'], $inner)
                : str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }

        return $trimmed;
    }

    private function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9._\/:-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
