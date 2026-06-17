<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use InvalidArgumentException;

final readonly class GatewayImageReference implements \Stringable
{
    private function __construct(
        private ?string $registry,
        private string $repository,
        private ?string $tag,
        private ?string $digest,
    ) {}

    public static function fromString(string $reference): self
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw new InvalidArgumentException('Gateway image reference cannot be empty.');
        }

        if (preg_match('/\s/', $reference) === 1) {
            throw new InvalidArgumentException('Gateway image reference cannot contain whitespace.');
        }

        $parts = explode('@', $reference);

        if (count($parts) > 2) {
            throw new InvalidArgumentException('Gateway image reference must contain at most one digest separator.');
        }

        $nameAndTag = $parts[0];
        $digest = $parts[1] ?? null;

        if ($digest !== null && preg_match('/^sha256:[a-f0-9]{64}$/', $digest) !== 1) {
            throw new InvalidArgumentException('Gateway image reference digest must use a sha256 digest.');
        }

        [$name, $tag] = self::splitNameAndTag($nameAndTag);

        if ($tag === null && $digest === null) {
            throw new InvalidArgumentException('Gateway image reference must include a tag or digest.');
        }

        [$registry, $repository] = self::splitRegistryAndRepository($name);
        self::assertValidRepository($repository);

        return new self($registry, $repository, $tag, $digest);
    }

    public function registry(): ?string
    {
        return $this->registry;
    }

    public function repository(): string
    {
        return $this->repository;
    }

    public function tag(): ?string
    {
        return $this->tag;
    }

    public function digest(): ?string
    {
        return $this->digest;
    }

    public function isDigestPinned(): bool
    {
        return $this->digest !== null;
    }

    public function canonical(): string
    {
        $reference = $this->registry !== null
            ? "{$this->registry}/{$this->repository}"
            : $this->repository;

        if ($this->tag !== null) {
            $reference .= ":{$this->tag}";
        }

        if ($this->digest !== null) {
            $reference .= "@{$this->digest}";
        }

        return $reference;
    }

    public function __toString(): string
    {
        return $this->canonical();
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private static function splitNameAndTag(string $nameAndTag): array
    {
        if ($nameAndTag === '') {
            throw new InvalidArgumentException('Gateway image reference name cannot be empty.');
        }

        $lastSlash = strrpos($nameAndTag, '/');
        $lastColon = strrpos($nameAndTag, ':');

        if ($lastColon === false || ($lastSlash !== false && $lastColon < $lastSlash)) {
            return [$nameAndTag, null];
        }

        $name = substr($nameAndTag, 0, $lastColon);
        $tag = substr($nameAndTag, $lastColon + 1);

        if ($tag === '') {
            throw new InvalidArgumentException('Gateway image reference must include a non-empty tag.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('Gateway image reference name cannot be empty.');
        }

        return [$name, $tag];
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private static function splitRegistryAndRepository(string $name): array
    {
        $segments = explode('/', $name);
        $first = $segments[0] ?? '';

        if (count($segments) > 1 && (str_contains($first, '.') || str_contains($first, ':') || $first === 'localhost')) {
            array_shift($segments);

            return [$first, implode('/', $segments)];
        }

        return [null, $name];
    }

    private static function assertValidRepository(string $repository): void
    {
        if ($repository === '' || str_starts_with($repository, '/') || str_ends_with($repository, '/')) {
            throw new InvalidArgumentException('Gateway image reference repository cannot be empty.');
        }

        foreach (explode('/', $repository) as $segment) {
            if (preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $segment) !== 1) {
                throw new InvalidArgumentException("Gateway image reference repository segment [{$segment}] is invalid.");
            }
        }
    }
}
