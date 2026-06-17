<?php

declare(strict_types=1);

use App\Services\AgentIde\AgentIdeAdapterRegistry;

it('returns deterministic core adapter descriptors without reserved tokens', function (): void {
    $registry = new AgentIdeAdapterRegistry;

    expect($registry->adapters())->toBe([
        [
            'name' => 'opencode',
            'label' => 'opencode',
            'source' => 'core',
            'capabilities' => ['message_delivery', 'workspace_path_resolution'],
        ],
        [
            'name' => 'polyscope',
            'label' => 'polyscope',
            'source' => 'core',
            'capabilities' => ['message_delivery', 'workspace_path_resolution'],
        ],
    ])
        ->and($registry->isRegisteredAdapter('opencode'))->toBeTrue()
        ->and($registry->isRegisteredAdapter('none'))->toBeFalse()
        ->and($registry->isRegisteredAdapter('inherit'))->toBeFalse();
});

it('returns command scoped choices with reserved tokens first', function (): void {
    $registry = new AgentIdeAdapterRegistry;

    expect($registry->choicesForScope('node'))->toBe([
        'reserved_tokens' => ['none'],
        'adapters' => $registry->adapters(),
    ])
        ->and($registry->supportedInputsForScope('node'))->toBe(['none', 'opencode', 'polyscope'])
        ->and($registry->choicesForScope('app'))->toBe([
            'reserved_tokens' => ['inherit', 'none'],
            'adapters' => $registry->adapters(),
        ])
        ->and($registry->supportedInputsForScope('app'))->toBe(['inherit', 'none', 'opencode', 'polyscope']);
});
