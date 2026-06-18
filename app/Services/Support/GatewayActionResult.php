<?php

declare(strict_types=1);

namespace App\Services\Support;

use JsonException;

final readonly class GatewayActionResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $exitCode,
        public array $payload,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && isset($this->payload['success']);
    }

    public function status(?callable $errorStatus = null): int
    {
        if ($this->successful()) {
            return 200;
        }

        if ($errorStatus !== null) {
            return (int) $errorStatus($this->payload);
        }

        return 422;
    }

    public static function fromJsonOutput(int $exitCode, ?string $output): self
    {
        $output = trim((string) $output);

        if ($output === '') {
            return self::error(
                'gateway.action_empty_output',
                'Gateway action did not produce a response payload.',
                [],
            );
        }

        try {
            $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::error(
                'gateway.action_invalid_output',
                'Gateway action produced an invalid response payload.',
                [],
            );
        }

        if (! is_array($payload)) {
            return self::error(
                'gateway.action_invalid_output',
                'Gateway action produced an invalid response payload.',
                [],
            );
        }

        /** @var array<string, mixed> $payload */
        return new self($exitCode, $payload);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function error(string $code, string $message, array $meta, int $exitCode = 1): self
    {
        return new self($exitCode, [
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ]);
    }
}
