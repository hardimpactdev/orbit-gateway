<?php

declare(strict_types=1);

use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('keeps node tools scoped to installed capabilities, not service runtime instances', function (): void {
    $columns = Schema::getColumnListing('node_tools');

    expect($columns)->toContain(
        'id',
        'node_id',
        'name',
        'expected_state',
        'expected_version',
        'config',
        'credentials',
    )
        ->and($columns)->not->toContain(
            'instance_key',
            'version_family',
            'runtime',
            'runtime_config',
        );
});

it('uses one node tool row per node and installed capability name', function (): void {
    $indexes = collect(Schema::getIndexes('node_tools'))
        ->filter(fn (array $index): bool => ($index['unique'] ?? false) === true)
        ->map(fn (array $index): array => $index['columns'] ?? [])
        ->values()
        ->all();

    expect(in_array(['node_id', 'name'], $indexes, true))->toBeTrue();
});

it('does not default node tool runtime or instance state on models', function (): void {
    $tool = new NodeTool([
        'name' => 'php',
        'expected_state' => 'installed',
    ]);

    expect($tool->getFillable())->toBe([
        'node_id',
        'name',
        'expected_state',
        'expected_version',
        'config',
        'credentials',
    ])
        ->and($tool->getAttributes())->not->toHaveKeys([
            'instance_key',
            'version_family',
            'runtime',
            'runtime_config',
        ]);
});
