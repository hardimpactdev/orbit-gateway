<?php

declare(strict_types=1);

use App\Services\DatabaseConnections\DatabaseQueryClassifier;

describe('DatabaseQueryClassifier', function (): void {
    it('classifies readonly statements as read operations', function (string $sql): void {
        $classification = app(DatabaseQueryClassifier::class)->classify($sql);

        expect($classification->mode)->toBe('read')
            ->and($classification->requiresWriteMode)->toBeFalse();
    })->with([
        'select' => ['select * from users'],
        'with' => ['with recent as (select * from users) select * from recent'],
        'explain' => ['explain select * from users'],
        'show' => ['show tables'],
        'describe' => ['describe users'],
        'desc' => ['desc users'],
        'mixed case describe' => ['dEsCrIbE users'],
        'pragma' => ['pragma table_info(users)'],
        'mixed case' => ['SeLeCt * from users'],
    ]);

    it('classifies write statements as requiring write mode', function (string $sql): void {
        $classification = app(DatabaseQueryClassifier::class)->classify($sql);

        expect($classification->mode)->toBe('write')
            ->and($classification->requiresWriteMode)->toBeTrue();
    })->with([
        'insert' => ['insert into users (name) values ("Nadia")'],
        'update' => ['update users set name = "Nadia"'],
        'delete' => ['delete from users'],
        'create' => ['create table users (id integer primary key, name text)'],
        'drop' => ['drop table users'],
        'alter' => ['alter table users add column email text'],
        'replace' => ['replace into users (id, name) values (1, "Nadia")'],
        'vacuum' => ['vacuum'],
        'write cte' => ['with deleted as (delete from users returning *) select * from deleted'],
    ]);

    it('ignores leading comments and whitespace when classifying', function (): void {
        $classification = app(DatabaseQueryClassifier::class)->classify('
            -- inspect user rows first
            /* dashboard query */
            select id, name from users
        ');

        expect($classification->mode)->toBe('read');
    });

    it('rejects blank sql', function (): void {
        app(DatabaseQueryClassifier::class)->classify('   ');
    })->throws(InvalidArgumentException::class, 'SQL is required.');

    it('rejects comment-only sql', function (): void {
        app(DatabaseQueryClassifier::class)->classify("-- just notes\n/* and more notes */");
    })->throws(InvalidArgumentException::class, 'SQL is required.');
});
