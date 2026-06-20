<?php

declare(strict_types=1);

use App\Services\Nodes\Access\NodePermissionRegistry;

describe('node permission registry', function (): void {
    it('defines a non-empty permission catalog', function (): void {
        $registry = new NodePermissionRegistry;

        expect($registry->all())->not->toBeEmpty();
    });

    it('recognizes the global wildcard', function (): void {
        expect((new NodePermissionRegistry)->isKnown('*'))->toBeTrue();
    });

    it('recognizes namespace wildcards', function (): void {
        $registry = new NodePermissionRegistry;

        expect($registry->isKnown('node:*'))->toBeTrue()
            ->and($registry->isKnown('tool:*'))->toBeTrue()
            ->and($registry->isKnown('app:*'))->toBeTrue();
    });

    it('recognizes known specific permissions', function (): void {
        $registry = new NodePermissionRegistry;

        expect($registry->isKnown('tool:read'))->toBeTrue()
            ->and($registry->isKnown('node:read'))->toBeTrue()
            ->and($registry->isKnown('app:credentials'))->toBeTrue()
            ->and($registry->isKnown('agent-ide:message'))->toBeTrue()
            ->and($registry->isKnown('database:read'))->toBeTrue()
            ->and($registry->isKnown('database:query:write'))->toBeTrue()
            ->and($registry->isKnown('doctor:verify'))->toBeTrue()
            ->and($registry->isKnown('firewall_rule:read'))->toBeTrue();
    });

    it('rejects unknown permissions', function (): void {
        expect((new NodePermissionRegistry)->isKnown('unknown:permission'))->toBeFalse()
            ->and((new NodePermissionRegistry)->isKnown('tool:hack'))->toBeFalse()
            ->and((new NodePermissionRegistry)->isKnown('app:exec'))->toBeFalse()
            ->and((new NodePermissionRegistry)->isKnown('workspace:exec'))->toBeFalse()
            ->and((new NodePermissionRegistry)->isKnown('role:update'))->toBeFalse()
            ->and((new NodePermissionRegistry)->isKnown('invalid'))->toBeFalse();
    });

    it('rejects unknown namespace wildcards', function (): void {
        expect((new NodePermissionRegistry)->isKnown('fake:*'))->toBeFalse();
    });

    it('returns implied permissions for tool:read', function (): void {
        $implied = (new NodePermissionRegistry)->impliedBy('tool:read');

        expect($implied)->toContain('tool:list')
            ->and($implied)->toContain('tool:show')
            ->and($implied)->not->toContain('tool:credentials');
    });

    it('returns implied permissions for tool:update', function (): void {
        $implied = (new NodePermissionRegistry)->impliedBy('tool:update');

        expect($implied)->toContain('tool:update:agent-tools');
    });

    it('returns implied permissions for database umbrellas', function (): void {
        $registry = new NodePermissionRegistry;

        expect($registry->impliedBy('database:read'))->toContain('database:list')
            ->and($registry->impliedBy('database:read'))->toContain('database:schema')
            ->and($registry->impliedBy('database:read'))->not->toContain('database:query')
            ->and($registry->impliedBy('database:write'))->toContain('database:add')
            ->and($registry->impliedBy('database:write'))->toContain('database:detach')
            ->and($registry->impliedBy('database:write'))->not->toContain('database:query:write')
            ->and($registry->impliedBy('database:query:write'))->toContain('database:query');
    });

    it('checks whether permission sets allow a required permission', function (): void {
        $registry = new NodePermissionRegistry;

        expect($registry->allows(['tool:read'], 'tool:show'))->toBeTrue()
            ->and($registry->allows(['tool:read'], 'tool:credentials'))->toBeFalse()
            ->and($registry->allows(['app:read'], 'app:credentials'))->toBeFalse()
            ->and($registry->allows(['app:write'], 'app:credentials'))->toBeFalse()
            ->and($registry->allows(['app:credentials'], 'app:credentials'))->toBeTrue()
            ->and($registry->allows(['app:*'], 'app:credentials'))->toBeTrue()
            ->and($registry->allows(['tool:update'], 'tool:update:agent-tools'))->toBeTrue()
            ->and($registry->allows(['tool:update:agent-tools'], 'tool:update'))->toBeFalse()
            ->and($registry->allows(['database:read'], 'database:tables'))->toBeTrue()
            ->and($registry->allows(['database:read'], 'database:query'))->toBeFalse()
            ->and($registry->allows(['database:query:write'], 'database:query'))->toBeTrue()
            ->and($registry->allows(['database:write'], 'database:query:write'))->toBeFalse()
            ->and($registry->allows(['node:*'], 'node:update'))->toBeTrue()
            ->and($registry->allows(['*'], 'firewall_rule:write'))->toBeTrue();
    });

    it('returns empty implied list for permissions without implications', function (): void {
        expect((new NodePermissionRegistry)->impliedBy('tool:credentials'))->toBe([])
            ->and((new NodePermissionRegistry)->impliedBy('app:credentials'))->toBe([])
            ->and((new NodePermissionRegistry)->impliedBy('doctor:verify'))->toBe([]);
    });

    it('returns all permissions for global wildcard', function (): void {
        $registry = new NodePermissionRegistry;
        $implied = $registry->impliedBy('*');

        expect($implied)->toContain('tool:read')
            ->and($implied)->toContain('node:read')
            ->and($implied)->not->toContain('*');
    });

    it('returns namespace permissions for namespace wildcard', function (): void {
        $registry = new NodePermissionRegistry;
        $implied = $registry->impliedBy('node:*');

        expect($implied)->toContain('node:read')
            ->and($implied)->toContain('node:show')
            ->and($implied)->not->toContain('tool:read')
            ->and($implied)->not->toContain('node:*');
    });

    it('returns namespace permissions for agent-ide wildcard', function (): void {
        $registry = new NodePermissionRegistry;

        expect($registry->isKnown('agent-ide:*'))->toBeTrue()
            ->and($registry->impliedBy('agent-ide:*'))->toBe(['agent-ide:message']);
    });

    it('reports coverage correctly', function (): void {
        $registry = new NodePermissionRegistry;

        expect($registry->isCoveredBy('tool:list', 'tool:read'))->toBeTrue()
            ->and($registry->isCoveredBy('tool:show', 'tool:read'))->toBeTrue()
            ->and($registry->isCoveredBy('tool:credentials', 'tool:read'))->toBeFalse()
            ->and($registry->isCoveredBy('database:show', 'database:read'))->toBeTrue()
            ->and($registry->isCoveredBy('database:query', 'database:read'))->toBeFalse()
            ->and($registry->isCoveredBy('node:read', '*'))->toBeTrue()
            ->and($registry->isCoveredBy('tool:read', 'node:*'))->toBeFalse()
            ->and($registry->isCoveredBy('node:show', 'node:*'))->toBeTrue();
    });

    it('returns unique namespaces', function (): void {
        $namespaces = (new NodePermissionRegistry)->namespaces();

        expect($namespaces->toArray())->toContain('node')
            ->and($namespaces->toArray())->toContain('tool')
            ->and($namespaces->toArray())->toContain('agent-ide')
            ->and($namespaces->toArray())->toContain('app')
            ->and($namespaces->toArray())->toContain('database')
            ->and($namespaces->toArray())->toContain('doctor')
            ->and($namespaces->toArray())->toContain('firewall_rule');
    });

    it('does not include global wildcard in implied results', function (): void {
        $registry = new NodePermissionRegistry;

        expect($registry->impliedBy('*'))->not->toContain('*')
            ->and($registry->impliedBy('node:*'))->not->toContain('node:*');
    });

    it('only declares known permissions as implication targets', function (): void {
        $registry = new NodePermissionRegistry;

        foreach ($registry->implications() as $permission => $impliedPermissions) {
            expect($registry->isKnown($permission))->toBeTrue("{$permission} should be known");

            foreach ($impliedPermissions as $impliedPermission) {
                expect($registry->isKnown($impliedPermission))->toBeTrue("{$impliedPermission} should be known");
            }
        }
    });
});
