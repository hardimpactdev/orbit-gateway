<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\S3RoleSettings;

it('defaults the data path when none is provided', function (): void {
    expect(S3RoleSettings::fromArray([])->toArray())
        ->toBe(['data_path' => '/srv/orbit/s3/data']);
});

it('accepts a custom absolute data path', function (): void {
    expect(S3RoleSettings::fromArray(['data_path' => '/mnt/disk/s3'])->toArray())
        ->toBe(['data_path' => '/mnt/disk/s3']);
});

it('rejects a non-absolute or empty data path', function (mixed $dataPath): void {
    expect(fn () => S3RoleSettings::fromArray(['data_path' => $dataPath]))
        ->toThrow(InvalidArgumentException::class, 'The s3 role requires an absolute data_path setting.');
})->with([
    'relative' => 'srv/orbit/s3/data',
    'empty' => '',
    'non-string' => 123,
]);

it('rejects unknown settings', function (): void {
    expect(fn () => S3RoleSettings::fromArray(['data_path' => '/srv/orbit/s3/data', 'bucket' => 'orbit']))
        ->toThrow(InvalidArgumentException::class, 'The s3 role does not accept unknown settings.');
});
