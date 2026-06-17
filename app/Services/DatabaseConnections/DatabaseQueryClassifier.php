<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use InvalidArgumentException;

final class DatabaseQueryClassifier
{
    private const array READ_TOKENS = [
        'select',
        'show',
        'describe',
        'desc',
        'explain',
        'pragma',
    ];

    public function classify(string $sql): DatabaseQueryClassification
    {
        $normalized = $this->stripLeadingComments($sql);

        if ($normalized === '') {
            throw new InvalidArgumentException('SQL is required.');
        }

        $token = $this->firstToken($normalized);

        if ($token === 'with') {
            return $this->classifyWithStatement($normalized);
        }

        $mode = in_array($token, self::READ_TOKENS, true) ? 'read' : 'write';

        return new DatabaseQueryClassification(
            mode: $mode,
            requiresWriteMode: $mode === 'write',
        );
    }

    private function classifyWithStatement(string $sql): DatabaseQueryClassification
    {
        $offset = strlen('with');
        $offset = $this->skipWhitespaceAndComments($sql, $offset);

        if (preg_match('/\Grecursive\b/i', $sql, $matches, 0, $offset) === 1) {
            $offset += strlen($matches[0]);
        }

        while (true) {
            $offset = $this->skipWhitespaceAndComments($sql, $offset);

            if (preg_match('/\G(?:"[^"]+"|`[^`]+`|[A-Za-z_][A-Za-z0-9_]*)(?:\s*\([^)]*\))?\s+as\s*\(/i', $sql, $matches, 0, $offset) !== 1) {
                return $this->writeClassification();
            }

            $openParen = $offset + strlen($matches[0]) - 1;
            $closeParen = $this->matchingParen($sql, $openParen);

            if ($closeParen === null) {
                return $this->writeClassification();
            }

            $body = substr($sql, $openParen + 1, $closeParen - $openParen - 1);
            $bodyClassification = $this->classify($body);

            if ($bodyClassification->requiresWriteMode) {
                return $bodyClassification;
            }

            $offset = $this->skipWhitespaceAndComments($sql, $closeParen + 1);

            if (($sql[$offset] ?? null) !== ',') {
                break;
            }

            $offset++;
        }

        $remaining = substr($sql, $offset);

        if ($this->stripLeadingComments($remaining) === '') {
            return $this->writeClassification();
        }

        return $this->classify($remaining);
    }

    private function writeClassification(): DatabaseQueryClassification
    {
        return new DatabaseQueryClassification(
            mode: 'write',
            requiresWriteMode: true,
        );
    }

    private function firstToken(string $sql): string
    {
        return strtolower(strtok($sql, " \t\r\n(") ?: $sql);
    }

    private function stripLeadingComments(string $sql): string
    {
        $remaining = substr($sql, $this->skipWhitespaceAndComments($sql, 0));

        return $remaining;
    }

    private function skipWhitespaceAndComments(string $sql, int $offset): int
    {
        $length = strlen($sql);

        while ($offset < $length) {
            while ($offset < $length && ctype_space($sql[$offset])) {
                $offset++;
            }

            if (substr($sql, $offset, 2) === '--') {
                $lineEnd = strpos($sql, "\n", $offset + 2);

                if ($lineEnd === false) {
                    return $length;
                }

                $offset = $lineEnd + 1;

                continue;
            }

            if (substr($sql, $offset, 2) === '/*') {
                $commentEnd = strpos($sql, '*/', $offset + 2);

                if ($commentEnd === false) {
                    return $length;
                }

                $offset = $commentEnd + 2;

                continue;
            }

            break;
        }

        return $offset;
    }

    private function matchingParen(string $sql, int $openParen): ?int
    {
        $length = strlen($sql);
        $depth = 0;
        $quote = null;

        for ($index = $openParen; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $sql[$index + 1] ?? '';

            if ($quote !== null) {
                if ($char === $quote) {
                    if (($quote === "'" || $quote === '"') && $next === $quote) {
                        $index++;

                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;

                continue;
            }

            if ($char === '-' && $next === '-') {
                $lineEnd = strpos($sql, "\n", $index + 2);
                $index = $lineEnd === false ? $length : $lineEnd;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $commentEnd = strpos($sql, '*/', $index + 2);

                if ($commentEnd === false) {
                    return null;
                }

                $index = $commentEnd + 1;

                continue;
            }

            if ($char === '(') {
                $depth++;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }
}

final readonly class DatabaseQueryClassification
{
    public function __construct(
        public string $mode,
        public bool $requiresWriteMode,
    ) {}
}
