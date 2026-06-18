<?php

declare(strict_types=1);

namespace App\Data\Apps;

use InvalidArgumentException;

final readonly class PhpWorkerConfig
{
    public const string DefaultWorkers = 'auto';

    public const int DefaultMaxRequests = 500;

    public function __construct(
        public string|int $workers = self::DefaultWorkers,
        public int $maxRequests = self::DefaultMaxRequests,
    ) {
        if (is_string($this->workers) && $this->workers !== 'auto') {
            throw new InvalidArgumentException("Worker config 'workers' must be the string 'auto' or a positive integer.");
        }

        if (is_int($this->workers) && $this->workers <= 0) {
            throw new InvalidArgumentException("Worker config 'workers' must be a positive integer when given as an int.");
        }

        if ($this->maxRequests <= 0) {
            throw new InvalidArgumentException("Worker config 'max_requests' must be a positive integer.");
        }
    }

    public static function default(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            workers: self::parseWorkers($data['workers'] ?? self::DefaultWorkers),
            maxRequests: self::parsePositiveInt($data['max_requests'] ?? self::DefaultMaxRequests, 'max_requests'),
        );
    }

    /**
     * @return array{workers: string|int, max_requests: int}
     */
    public function toArray(): array
    {
        return [
            'workers' => $this->workers,
            'max_requests' => $this->maxRequests,
        ];
    }

    private static function parseWorkers(mixed $value): string|int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === 'auto') {
                return 'auto';
            }

            if (ctype_digit($trimmed)) {
                return (int) $trimmed;
            }
        }

        throw new InvalidArgumentException("Worker config 'workers' must be 'auto' or a positive integer.");
    }

    private static function parsePositiveInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit(trim($value))) {
            return (int) trim($value);
        }

        throw new InvalidArgumentException("Worker config '{$field}' must be a positive integer.");
    }
}
