<?php

declare(strict_types=1);

use App\Services\DatabaseConnections\EnvFileEditor;
use Tests\TestCase;

uses(TestCase::class);

describe('EnvFileEditor', function (): void {
    it('parses quoted and unquoted values', function (): void {
        $editor = app(EnvFileEditor::class);
        $contents = <<<'ENV'
APP_NAME=Orbit
DB_PASSWORD="p@ss word"
DB_USERNAME='orbit\'user'
DB_DATABASE=/srv/apps/orbit/database.sqlite
EMPTY_VALUE=
ENV;

        expect($editor->parse($contents))->toBe([
            'APP_NAME' => 'Orbit',
            'DB_PASSWORD' => 'p@ss word',
            'DB_USERNAME' => "orbit'user",
            'DB_DATABASE' => '/srv/apps/orbit/database.sqlite',
            'EMPTY_VALUE' => '',
        ]);
    });

    it('updates existing keys and appends missing keys while preserving comments and unrelated lines', function (): void {
        $editor = app(EnvFileEditor::class);
        $contents = <<<'ENV'
# Application
APP_NAME=Orbit
DB_HOST=127.0.0.1
DB_PASSWORD='old-password'

# Keep this comment
QUEUE_CONNECTION=database
ENV;

        $updated = $editor->update($contents, [
            'DB_HOST' => 'db.internal',
            'DB_PORT' => '5432',
            'DB_PASSWORD' => 'new password',
        ]);

        expect($updated)->toBe(<<<'ENV'
# Application
APP_NAME=Orbit
DB_HOST=db.internal
DB_PASSWORD="new password"

# Keep this comment
QUEUE_CONNECTION=database
DB_PORT=5432
ENV);
    });

    it('emits dotenv safe quoted values for non simple tokens and parses them back', function (): void {
        $editor = app(EnvFileEditor::class);
        $contents = $editor->update('', [
            'HASH_VALUE' => 'abc#123',
            'EQUALS_VALUE' => 'abc=123',
            'SPACED_VALUE' => 'abc 123',
            'QUOTE_VALUE' => 'say "hi"',
            'SINGLE_QUOTE_VALUE' => "it's here",
            'BACKSLASH_VALUE' => 'C:\orbit\data',
        ]);

        expect($contents)->toBe(<<<'ENV'
HASH_VALUE="abc#123"
EQUALS_VALUE="abc=123"
SPACED_VALUE="abc 123"
QUOTE_VALUE="say \"hi\""
SINGLE_QUOTE_VALUE="it's here"
BACKSLASH_VALUE="C:\\orbit\\data"
ENV)
            ->and($editor->parse($contents))->toBe([
                'HASH_VALUE' => 'abc#123',
                'EQUALS_VALUE' => 'abc=123',
                'SPACED_VALUE' => 'abc 123',
                'QUOTE_VALUE' => 'say "hi"',
                'SINGLE_QUOTE_VALUE' => "it's here",
                'BACKSLASH_VALUE' => 'C:\orbit\data',
            ]);
    });

    it('updates export assignments and preserves the export prefix', function (): void {
        $editor = app(EnvFileEditor::class);
        $contents = <<<'ENV'
export DB_HOST=127.0.0.1
DB_PORT=3306
ENV;

        $updated = $editor->update($contents, [
            'DB_HOST' => 'db.internal',
            'DB_PORT' => '3307',
        ]);

        expect($updated)->toBe(<<<'ENV'
export DB_HOST=db.internal
DB_PORT=3307
ENV);
    });

    it('preserves CRLF line endings when updating', function (): void {
        $editor = app(EnvFileEditor::class);
        $contents = "APP_NAME=Orbit\r\nDB_HOST=127.0.0.1\r\n";

        $updated = $editor->update($contents, [
            'DB_HOST' => 'db.internal',
            'DB_PORT' => '5432',
        ]);

        expect($updated)->toBe("APP_NAME=Orbit\r\nDB_HOST=db.internal\r\nDB_PORT=5432\r\n");
    });

    it('updates all duplicate key occurrences to avoid stale values', function (): void {
        $editor = app(EnvFileEditor::class);
        $contents = <<<'ENV'
DB_HOST=127.0.0.1
DB_HOST=localhost
ENV;

        $updated = $editor->update($contents, [
            'DB_HOST' => 'db.internal',
        ]);

        expect($updated)->toBe(<<<'ENV'
DB_HOST=db.internal
DB_HOST=db.internal
ENV)
            ->and($editor->parse($updated))->toBe([
                'DB_HOST' => 'db.internal',
            ]);
    });
});
