<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Process\Process;

/**
 * @return array<string, mixed>
 */
function gatewayCommandList(): array
{
    $process = new Process([PHP_BINARY, 'artisan', 'list', '--format=json'], base_path());
    $process->mustRun();

    return json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $commandList
 * @return list<string>
 */
function gatewayVisibleCommandNames(array $commandList): array
{
    return array_values(array_column(
        array_filter($commandList['commands'], fn (array $command): bool => ! ($command['hidden'] ?? false)),
        'name',
    ));
}

/**
 * @return list<array{name: string, class: class-string}>
 */
function gatewayApplicationCommands(): array
{
    return collect(Artisan::all())
        ->map(fn (SymfonyCommand $command, string $name): array => [
            'name' => $name,
            'class' => get_class($command),
        ])
        ->filter(fn (array $command): bool => str_starts_with($command['class'], 'App\\Console\\Commands\\'))
        ->sortBy('name')
        ->values()
        ->all();
}

function gatewayAllowedArtisanCommandName(string $name): bool
{
    if ($name === 'orbit-scheduler') {
        return true;
    }

    return array_any(
        [
            'orbit:internal:',
        ],
        fn (string $prefix): bool => str_starts_with($name, $prefix),
    );
}

/**
 * @return array<string, array{
 *     command: string,
 *     class: string,
 *     cli_owner: string,
 *     call_sites: string,
 *     classification: string,
 *     removal_todo: string,
 *     required_tests: string
 * }>
 */
function gatewayCommandInventoryRows(): array
{
    $path = repo_path('docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md');

    expect(file_exists($path))->toBeTrue("Missing gateway command inventory at {$path}");

    $rows = [];

    foreach (explode("\n", file_get_contents($path)) as $line) {
        if (! str_starts_with($line, '|')) {
            continue;
        }

        $columns = array_map(
            fn (string $column): string => trim($column, " \t\n\r\0\x0B`"),
            explode('|', trim($line, '|')),
        );

        if (count($columns) < 7 || $columns[0] === 'command name' || str_starts_with($columns[0], '---')) {
            continue;
        }

        $rows[$columns[0]] = [
            'command' => $columns[0],
            'class' => $columns[1],
            'cli_owner' => $columns[2],
            'call_sites' => $columns[3],
            'classification' => $columns[4],
            'removal_todo' => $columns[5],
            'required_tests' => $columns[6],
        ];
    }

    return $rows;
}

/**
 * @return list<string>
 */
function gatewayRegisteredCommandNames(): array
{
    return array_keys(Artisan::all());
}

/**
 * @return list<string>
 */
function gatewayRemovedAppNodeLocalCommandNames(): array
{
    return [
        'app:agent-ide',
        'app:list',
        'app:new',
        'app:prune',
        'app:register',
        'app:remove',
        'app:root',
        'app:show',
        'app:worker',
        'dns:list',
        'dns:resolve-tld',
        'doctor',
        'gateway:add',
        'gateway:trust',
        'node role:add',
        'node role:list',
        'node role:remove',
        'node:agent-ide',
        'node:grant',
        'node:list',
        'node:new',
        'node:permissions',
        'node:remove',
        'node:revoke',
        'node:show',
        'node:update',
        'php:list',
        'php:use',
        'profile',
        'update',
        'update:all',
    ];
}

/**
 * @return list<string>
 */
function gatewayRemovedResourceCommandNames(): array
{
    return [
        'database:add',
        'database:attach',
        'database:describe',
        'database:detach',
        'database:list',
        'database:query',
        'database:remove',
        'database:schema',
        'database:show',
        'database:tables',
        'database:update',
        'process:add',
        'process:edit',
        'process:list',
        'process:logs',
        'process:remove',
        'process:restart',
        'process:start',
        'process:stop',
        'schedule:add',
        'schedule:list',
        'schedule:logs',
        'schedule:remove',
        'schedule:run',
        'schedule:show',
        'workspace-setup-step:add',
        'workspace-setup-step:list',
        'workspace-setup-step:remove',
        'workspace-teardown-step:add',
        'workspace-teardown-step:list',
        'workspace-teardown-step:remove',
        'workspace:history',
        'workspace:list',
        'workspace:log',
        'workspace:new',
        'workspace:remove',
        'workspace:setup',
        'workspace:show',
    ];
}

/**
 * @return list<string>
 */
function gatewayRemovedResourceCommandNamesWithoutFrameworkCollisions(): array
{
    return array_values(array_diff(gatewayRemovedResourceCommandNames(), [
        'schedule:list',
        'schedule:run',
    ]));
}

/**
 * @return list<string>
 */
function gatewayRemovedInfraToolCommandNames(): array
{
    return [
        'activity:list',
        'activity:show',
        'agent-ide:message',
        'cf-cache-rule:add',
        'cf-cache-rule:remove',
        'cf-cache:flush',
        'cf-dns:add',
        'cf-dns:list',
        'cf-dns:remove',
        'cf-ssl:disable',
        'cf-ssl:enable',
        'cf-zone:list',
        'deploy:history',
        'deploy:log',
        'deploy:run',
        'deploy:step-add',
        'deploy:step-list',
        'deploy:step-remove',
        'firewall:allow',
        'firewall:deny',
        'firewall:list',
        'firewall:remove',
        'proxy:add',
        'proxy:list',
        'proxy:remove',
        'tool:credentials',
        'tool:install',
        'tool:list',
        'tool:reconfigure',
        'tool:remove',
        'tool:show',
        'tool:update',
        'vpn-client:disable',
        'vpn-client:enable',
        'vpn-client:list',
        'vpn-client:new',
        'vpn-client:remove',
        'vpn-web-ui:change-password',
    ];
}

/**
 * @param  list<string>  $commandNames
 */
function expectGatewayCommandsToBeRemoved(array $commandNames): void
{
    expect(gatewayRegisteredCommandNames())->not->toContain(...$commandNames);
}

it('hides CLI-owned public product commands from the gateway command list', function (): void {
    $visible = gatewayVisibleCommandNames(gatewayCommandList());

    expect($visible)->not->toContain(
        'doctor',
        'profile',
        'update',
        'activity:list',
        'app:list',
        'app:new',
        'app:register',
        'app:root',
        'app:worker',
        'cf-dns:list',
        'database:list',
        'deploy:run',
        'dns:list',
        'firewall:list',
        'gateway:add',
        'node:list',
        'node:new',
        'node role:add',
        'node role:list',
        'process:list',
        'proxy:list',
        'schedule:add',
        'schedule:list',
        'schedule:run',
        'tool:list',
        'update:all',
        'vpn-client:list',
        'workspace:list',
    );
});

it('shows gateway service and maintenance commands in the command list', function (): void {
    $visible = gatewayVisibleCommandNames(gatewayCommandList());

    expect($visible)->toContain(
        'migrate',
        'migrate:status',
        'queue:work',
        'cache:clear',
        'db:show',
        'make:model',
        'schedule:work',
        'tinker',
        'docs',
        'librarian:lint',
        'orbit-scheduler',
        'orbit:internal:bake-app-node',
        'orbit:internal:bootstrap-gateway-local',
        'orbit:internal:node-register',
    )->not->toContain(
        'boost:install',
        'mcp:start',
        'pail',
        'roster:scan',
    );
});

it('classifies every invokable non-allowed gateway command in the inventory', function (): void {
    $inventoryRows = gatewayCommandInventoryRows();
    $allowedClassifications = [
        'delete',
        'port-cli-coverage-first',
        'internalize-extract-first',
        'keep',
    ];

    $registeredCommands = collect(gatewayApplicationCommands());
    $registeredCommandNames = $registeredCommands
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
    $inventoryCommandNames = collect(array_keys($inventoryRows))
        ->sort()
        ->values()
        ->all();

    expect($inventoryCommandNames)->toBe($registeredCommandNames);

    $unclassified = $registeredCommands
        ->reject(fn (array $command): bool => gatewayAllowedArtisanCommandName($command['name']))
        ->reject(fn (array $command): bool => array_key_exists($command['name'], $inventoryRows))
        ->map(fn (array $command): string => "{$command['name']} ({$command['class']})")
        ->values()
        ->all();

    expect($unclassified)->toBe([]);

    $registeredByName = $registeredCommands->keyBy('name');

    foreach ($inventoryRows as $row) {
        expect($row['classification'])->toBeIn($allowedClassifications);
        expect($row['class'])->not->toBe('');
        expect($row['removal_todo'])->not->toBe('');
        expect($row['required_tests'])->not->toBe('');

        if ($registeredByName->has($row['command'])) {
            expect($row['class'])->toBe($registeredByName->get($row['command'])['class']);
        }
    }

    expect(array_keys($inventoryRows))->toContain('orbit:internal:node-register');

    expect(array_keys($inventoryRows))->not->toContain(
        ...gatewayRemovedResourceCommandNames(),
        ...gatewayRemovedInfraToolCommandNames(),
    );
});

it('keeps hidden framework commands directly invocable', function (): void {
    $process = new Process([PHP_BINARY, 'artisan', 'help', 'migrate:status'], base_path());
    $process->mustRun();

    expect($process->isSuccessful())->toBeTrue();
});

it('removes app, node, and local public product commands from gateway Artisan', function (): void {
    $registeredCommandNames = collect(gatewayApplicationCommands())
        ->pluck('name')
        ->all();

    expect($registeredCommandNames)->not->toContain(...gatewayRemovedAppNodeLocalCommandNames());

    expectGatewayCommandsToBeRemoved(gatewayRemovedAppNodeLocalCommandNames());
});

it('removes resource public product commands from gateway Artisan', function (): void {
    $registeredCommandNames = collect(gatewayApplicationCommands())
        ->pluck('name')
        ->all();

    expect($registeredCommandNames)->not->toContain(...gatewayRemovedResourceCommandNames());

    expectGatewayCommandsToBeRemoved(gatewayRemovedResourceCommandNamesWithoutFrameworkCollisions());
});

it('removes infra and tool public product commands from gateway Artisan', function (): void {
    $registeredCommandNames = collect(gatewayApplicationCommands())
        ->pluck('name')
        ->all();

    expect($registeredCommandNames)->not->toContain(...gatewayRemovedInfraToolCommandNames());

    expectGatewayCommandsToBeRemoved(gatewayRemovedInfraToolCommandNames());
});

it('uses the Orbit CLI name independent of local environment drift', function (): void {
    $process = new Process([PHP_BINARY, 'artisan', '--version', '--no-ansi'], base_path(), [
        'APP_NAME' => 'Laravel',
    ]);
    $process->mustRun();

    expect(trim($process->getOutput()))->toBe('Orbit '.config('app.version'));
});
