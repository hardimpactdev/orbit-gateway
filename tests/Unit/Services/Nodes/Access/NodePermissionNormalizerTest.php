<?php

declare(strict_types=1);

use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionRegistry;

describe('node permission normalizer', function (): void {
    beforeEach(function (): void {
        $this->normalizer = new NodePermissionNormalizer(new NodePermissionRegistry);
    });

    it('removes duplicate permissions', function (): void {
        $result = $this->normalizer->normalize(['tool:read', 'tool:read', 'node:read']);

        expect($result->permissions)->toBe(['node:read', 'tool:read']);
    });

    it('removes permissions implied by another in the set', function (): void {
        $result = $this->normalizer->normalize(['tool:read', 'tool:list', 'tool:show']);

        expect($result->permissions)->toBe(['tool:read']);
        expect($result->removed)->toContain('tool:list')
            ->and($result->removed)->toContain('tool:show');
    });

    it('does not remove tool:credentials under tool:read', function (): void {
        $result = $this->normalizer->normalize(['tool:read', 'tool:credentials']);

        expect($result->permissions)->toBe(['tool:credentials', 'tool:read']);
    });

    it('does not remove app credentials under app read or write permissions', function (): void {
        $result = $this->normalizer->normalize(['app:read', 'app:write', 'app:credentials']);

        expect($result->permissions)->toBe(['app:credentials', 'app:read', 'app:write']);
    });

    it('handles tool:update implying tool:update:agent-tools', function (): void {
        $result = $this->normalizer->normalize(['tool:update', 'tool:update:agent-tools']);

        expect($result->permissions)->toBe(['tool:update']);
        expect($result->removed)->toContain('tool:update:agent-tools');
    });

    it('does not imply tool:update from tool:update:agent-tools', function (): void {
        $result = $this->normalizer->normalize(['tool:update:agent-tools', 'tool:update']);

        expect($result->permissions)->toBe(['tool:update']);
    });

    it('collapses to global wildcard when present', function (): void {
        $result = $this->normalizer->normalize(['tool:read', 'node:read', '*']);

        expect($result->permissions)->toBe(['*']);
        expect($result->removed)->toContain('node:read')
            ->and($result->removed)->toContain('tool:read');
    });

    it('collapses namespace wildcard over specific permissions', function (): void {
        $result = $this->normalizer->normalize(['node:read', 'node:show', 'node:*']);

        expect($result->permissions)->toBe(['node:*']);
        expect($result->removed)->toContain('node:read')
            ->and($result->removed)->toContain('node:show');
    });

    it('rejects unknown permissions', function (): void {
        expect(fn () => $this->normalizer->normalize(['tool:read', 'unknown:permission']))
            ->toThrow(InvalidArgumentException::class, 'Unknown permission [unknown:permission].');
    });

    it('returns empty for empty input', function (): void {
        $result = $this->normalizer->normalize([]);

        expect($result->permissions)->toBe([])
            ->and($result->removed)->toBe([]);
    });

    it('reports removed permissions in the warnings list', function (): void {
        $result = $this->normalizer->normalize([
            'tool:read',
            'tool:list',
            'tool:show',
            'node:read',
            'node:show',
        ]);

        expect($result->permissions)->toBe(['node:read', 'tool:read']);
        expect($result->removed)->toContain('node:show')
            ->and($result->removed)->toContain('tool:list')
            ->and($result->removed)->toContain('tool:show');
    });

    it('does not remove unrelated permissions', function (): void {
        $result = $this->normalizer->normalize([
            'app:read',
            'database:read',
            'doctor:verify',
            'firewall_rule:read',
            'node:read',
            'tool:read',
            'tool:update',
        ]);

        expect($result->permissions)->toBe([
            'app:read',
            'database:read',
            'doctor:verify',
            'firewall_rule:read',
            'node:read',
            'tool:read',
            'tool:update',
        ]);
        expect($result->removed)->toBe([]);
    });

    it('removes implied permissions from multiple umbrellas', function (): void {
        $result = $this->normalizer->normalize([
            'node:read',
            'node:show',
            'tool:read',
            'tool:list',
            'app:read',
            'app:show',
        ]);

        expect($result->permissions)->toBe(['app:read', 'node:read', 'tool:read']);
        expect($result->removed)->toContain('app:show')
            ->and($result->removed)->toContain('node:show')
            ->and($result->removed)->toContain('tool:list');
    });

    it('handles node namespace wildcard', function (): void {
        $result = $this->normalizer->normalize(['node:*', 'node:read', 'node:grant']);

        expect($result->permissions)->toBe(['node:*']);
        expect($result->removed)->toContain('node:grant')
            ->and($result->removed)->toContain('node:read');
    });

    it('preserves tool:update:agent-tools without tool:update', function (): void {
        $result = $this->normalizer->normalize(['tool:update:agent-tools']);

        expect($result->permissions)->toBe(['tool:update:agent-tools']);
        expect($result->removed)->toBe([]);
    });
});
