<?php

declare(strict_types=1);

namespace App\Services\Operations;

/**
 * Shared recognition + rejection rules for typed operation results and any
 * future framed progress payloads that may later be persisted or broadcast.
 *
 * The pattern set comes from the "Result-boundary redaction patterns"
 * subsection in apps/docs/content/execution-lanes.md. Every persistence
 * boundary (the typed-result handler and any future progress-frame
 * recorder/broadcaster) MUST run the same policy: unknown keys for the
 * declared operation contract fail closed, and any key whose name contains
 * a forbidden secret fragment — or whose value embeds a PEM block — is
 * rejected before the payload reaches `operation_runs` or `activity_log`.
 */
final readonly class ResultBoundaryRedactionPolicy
{
    /**
     * Substrings (case-insensitive) that may not appear anywhere in a key
     * name. `_token` and `api_key` catch suffix-styled variants such as
     * `access_token`, `refresh_token`, `csrf_token`, `api_key_id`.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_KEY_FRAGMENTS = [
        'operation_token',
        'executor_secret',
        'password',
        'bearer',
        'secret',
        '_token',
        'api_key',
    ];

    private const string PEM_BLOCK_PATTERN = '/-----BEGIN [A-Z ]+-----[\s\S]*?-----END [A-Z ]+-----/';

    /**
     * Assert a payload contains no forbidden key fragments and no PEM blocks
     * in any leaf string. Throws {@see OperationPayloadRejected} on the
     * first violation it finds, naming the offending key/path.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function assertSafe(array $payload, string $context = 'result'): void
    {
        $violation = $this->findViolation($payload);

        if ($violation === null) {
            return;
        }

        throw new OperationPayloadRejected(
            "operation.{$context}_unsafe: rejected payload at '{$violation['path']}' ({$violation['reason']}).",
            errorCode: 'operation.'.$context.'_unsafe',
            meta: [
                'path' => $violation['path'],
                'reason' => $violation['reason'],
            ],
        );
    }

    /**
     * Check whether a single key name contains a forbidden secret fragment.
     */
    public function isForbiddenKey(string $key): bool
    {
        $lower = strtolower($key);

        return array_any(self::FORBIDDEN_KEY_FRAGMENTS, fn ($fragment) => str_contains($lower, (string) $fragment));
    }

    /**
     * Check whether a string value contains a PEM block.
     */
    public function valueContainsPem(string $value): bool
    {
        return preg_match(self::PEM_BLOCK_PATTERN, $value) === 1;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{path: string, reason: string}|null
     */
    private function findViolation(array $payload, string $prefix = ''): ?array
    {
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            // Integer keys cannot carry forbidden name fragments; the value scan below still
            // catches PEM blocks regardless of whether the key is a string or an integer.
            if (is_string($key) && $this->isForbiddenKey($key)) {
                return [
                    'path' => $path,
                    'reason' => 'forbidden_key',
                ];
            }

            if (is_string($value) && $this->valueContainsPem($value)) {
                return [
                    'path' => $path,
                    'reason' => 'pem_block_value',
                ];
            }

            if (is_array($value)) {
                $nested = $this->findViolation($value, $path);

                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }
}
