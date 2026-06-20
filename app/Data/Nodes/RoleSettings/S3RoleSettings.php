<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class S3RoleSettings implements NodeRoleSettings
{
    public const string DefaultDataPath = '/srv/orbit/s3/data';

    public function __construct(
        public string $dataPath = self::DefaultDataPath,
    ) {
        if (! self::isAbsolutePath($dataPath)) {
            throw new InvalidArgumentException('The s3 role requires an absolute data_path setting.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $unknownKeys = array_diff(array_keys($settings), ['data_path']);

        if ($unknownKeys !== []) {
            throw new InvalidArgumentException('The s3 role does not accept unknown settings.');
        }

        if (! array_key_exists('data_path', $settings)) {
            return new self;
        }

        $dataPath = $settings['data_path'];

        if (! is_string($dataPath) || ! self::isAbsolutePath($dataPath)) {
            throw new InvalidArgumentException('The s3 role requires an absolute data_path setting.');
        }

        return new self($dataPath);
    }

    #[\Override]
    public function toArray(): array
    {
        return ['data_path' => $this->dataPath];
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path !== '' && str_starts_with($path, '/');
    }
}
