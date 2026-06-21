<?php

declare(strict_types=1);

use App\Services\DatabaseConnections\DatabaseConnectionEnvMapper;
use App\Services\DatabaseConnections\DatabaseConnectionPayload;
use Tests\TestCase;

uses(TestCase::class);

describe('DatabaseConnectionEnvMapper', function (): void {
    it('maps the default DB prefix to the expected env keys', function (): void {
        $payload = new DatabaseConnectionPayload(
            driver: 'pgsql',
            host: 'db.internal',
            port: 5432,
            database: 'orbit',
            path: null,
            username: 'orbit_user',
            password: 'secret',
        );

        $mapped = app(DatabaseConnectionEnvMapper::class)->toEnvValues('DB', $payload);

        expect($mapped)->toBe([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'db.internal',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'orbit',
            'DB_USERNAME' => 'orbit_user',
            'DB_PASSWORD' => 'secret',
        ]);
    });

    it('maps custom prefixes generically using prefix plus suffix keys', function (): void {
        $payload = new DatabaseConnectionPayload(
            driver: 'mysql',
            host: 'analytics.internal',
            port: 3306,
            database: 'analytics',
            path: null,
            username: 'analytics_user',
            password: 'topsecret',
        );

        $mapped = app(DatabaseConnectionEnvMapper::class)->toEnvValues('ANALYTICS_DB', $payload);

        expect($mapped)->toBe([
            'ANALYTICS_DB_CONNECTION' => 'mysql',
            'ANALYTICS_DB_HOST' => 'analytics.internal',
            'ANALYTICS_DB_PORT' => '3306',
            'ANALYTICS_DB_DATABASE' => 'analytics',
            'ANALYTICS_DB_USERNAME' => 'analytics_user',
            'ANALYTICS_DB_PASSWORD' => 'topsecret',
        ]);
    });

    it('maps sqlite path to the prefixed database env key', function (): void {
        $payload = new DatabaseConnectionPayload(
            driver: 'sqlite',
            host: null,
            port: null,
            database: null,
            path: '/srv/apps/orbit/database/database.sqlite',
            username: null,
            password: null,
        );

        $mapped = app(DatabaseConnectionEnvMapper::class)->toEnvValues('ANALYTICS_DB', $payload);

        expect($mapped)->toBe([
            'ANALYTICS_DB_CONNECTION' => 'sqlite',
            'ANALYTICS_DB_DATABASE' => '/srv/apps/orbit/database/database.sqlite',
        ]);
    });

    it('prefers sqlite path over database when both are present', function (): void {
        $payload = new DatabaseConnectionPayload(
            driver: 'sqlite',
            host: null,
            port: null,
            database: 'fallback.sqlite',
            path: '/srv/apps/orbit/database/database.sqlite',
            username: null,
            password: null,
        );

        $mapped = app(DatabaseConnectionEnvMapper::class)->toEnvValues('DB', $payload);

        expect($mapped)->toBe([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/srv/apps/orbit/database/database.sqlite',
        ]);
    });
});

describe('DatabaseConnectionPayload', function (): void {
    it('rejects a missing driver', function (): void {
        expect(fn () => DatabaseConnectionPayload::fromArray([
            'host' => 'db.internal',
        ]))->toThrow(InvalidArgumentException::class, 'driver');
    });

    it('rejects an unsupported driver', function (): void {
        expect(fn () => DatabaseConnectionPayload::fromArray([
            'driver' => 'sqlserver',
        ]))->toThrow(InvalidArgumentException::class, 'sqlserver');
    });

    it('accepts the supported drivers', function (string $driver): void {
        $payload = DatabaseConnectionPayload::fromArray([
            'driver' => $driver,
        ]);

        expect($payload->driver)->toBe($driver);
    })->with(['mysql', 'pgsql', 'sqlite']);
});
