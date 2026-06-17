<?php

declare(strict_types=1);

use App\Services\Nodes\NodeCreationRoleInputException;
use App\Services\Nodes\NodeCreationRoleResolver;
use Tests\TestCase;

uses(TestCase::class);

it('treats omitted template and roles as a client identity request', function (): void {
    $selection = app(NodeCreationRoleResolver::class)->resolve(
        template: null,
        operator: false,
        roles: null,
    );

    expect($selection->clientIdentity)->toBeTrue()
        ->and($selection->operator)->toBeFalse()
        ->and($selection->gateway)->toBeFalse()
        ->and($selection->hosted)->toBe([])
        ->and($selection->requestedRoleMeta)->toBe('client');
});

it('expands node templates to canonical stored roles', function (string $template, array $hosted, bool $gateway = false, bool $operator = false): void {
    $selection = app(NodeCreationRoleResolver::class)->resolve(
        template: $template,
        operator: false,
        roles: null,
    );

    expect($selection->template)->toBe($template)
        ->and($selection->hosted)->toBe($hosted)
        ->and($selection->gateway)->toBe($gateway)
        ->and($selection->operator)->toBe($operator);
})->with([
    'operator' => ['operator', [], false, true],
    'gateway' => ['gateway', [], true, false],
    'app development' => ['app-development', ['app-dev', 'database'], false, false],
    'app production' => ['app-production', ['app-prod'], false, false],
    'ingress' => ['ingress', ['ingress'], false, false],
    'database' => ['database', ['database'], false, false],
    'agent' => ['agent', ['agent'], false, false],
]);

it('resolves comma-separated programmatic roles without template expansion', function (): void {
    $selection = app(NodeCreationRoleResolver::class)->resolve(
        template: null,
        operator: false,
        roles: 'app-dev,database',
    );

    expect($selection->template)->toBeNull()
        ->and($selection->hosted)->toBe(['app-dev', 'database'])
        ->and($selection->requestedRoleMeta)->toBe('app-dev');
});

it('rejects template and explicit roles together', function (): void {
    try {
        app(NodeCreationRoleResolver::class)->resolve(
            template: 'app-development',
            operator: false,
            roles: 'app-dev',
        );
    } catch (NodeCreationRoleInputException $exception) {
        expect($exception->errorCode)->toBe('validation_failed')
            ->and($exception->getMessage())->toBe('--template and --roles cannot be used together.')
            ->and($exception->meta)->toBe(['fields' => ['template', 'roles']]);

        return;
    }

    $this->fail('Expected role input validation to fail.');
});

it('rejects retired aggregate role values without aliases', function (): void {
    try {
        app(NodeCreationRoleResolver::class)->resolve(
            template: null,
            operator: false,
            roles: 'app-development',
        );
    } catch (NodeCreationRoleInputException $exception) {
        expect($exception->errorCode)->toBe('validation_failed')
            ->and($exception->meta)->toBe(['field' => 'roles']);

        return;
    }

    $this->fail('Expected retired role input to fail.');
});
