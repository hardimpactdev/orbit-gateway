<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\RemoteShell\Exceptions\LocalExecutorCommandBuilderException;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

uses(TestCase::class);

describe(LocalExecutorCommandBuilder::class, function (): void {
    it('builds the verify command with an operation token and json output', function (): void {
        $command = localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: 'internal:executor:verify',
            arguments: [],
            options: [],
            operationToken: 'token-abc',
        );

        expect($command)->toBe("/usr/local/bin/orbit internal:executor:verify --operation-token='token-abc' --json");
    });

    it('uses the configured local executor binary path when provided', function (): void {
        config()->set('orbit.local_executor_binary', '/usr/local/bin/orbit-cli');

        $command = localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['vpn']),
            commandName: 'internal:wg-easy:state',
            arguments: ['state:list-users'],
            options: [],
            operationToken: 'token-abc',
        );

        expect($command)->toBe(escapeshellarg('/usr/local/bin/orbit-cli')." internal:wg-easy:state 'state:list-users' --operation-token='token-abc' --json");
    });

    it('appends escaped positional arguments after the command name', function (): void {
        $command = localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['app-dev']),
            commandName: 'internal:workspace-adapter:lookup',
            arguments: ['two words', "quote'arg", 7, 1.5, true, false],
            options: [],
            operationToken: 'token-abc',
        );

        expect($command)->toBe(implode(' ', [
            '/usr/local/bin/orbit',
            'internal:workspace-adapter:lookup',
            escapeshellarg('two words'),
            escapeshellarg("quote'arg"),
            escapeshellarg('7'),
            escapeshellarg('1.5'),
            escapeshellarg('1'),
            escapeshellarg('0'),
            "--operation-token='token-abc'",
            '--json',
        ]));
    });

    it('appends escaped option values after positional arguments', function (): void {
        $command = localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['vpn']),
            commandName: 'internal:wg-easy:state',
            arguments: ['state:update-user'],
            options: [
                'user-id' => 42,
                'state-path' => "/srv/wg easy/db's.sqlite",
                'enabled' => true,
                'locked' => false,
            ],
            operationToken: 'token-abc',
        );

        expect($command)->toBe(implode(' ', [
            '/usr/local/bin/orbit',
            'internal:wg-easy:state',
            escapeshellarg('state:update-user'),
            '--user-id='.escapeshellarg('42'),
            '--state-path='.escapeshellarg("/srv/wg easy/db's.sqlite"),
            '--enabled='.escapeshellarg('1'),
            '--locked='.escapeshellarg('0'),
            "--operation-token='token-abc'",
            '--json',
        ]));
    });

    it('escapes operation tokens before appending json output', function (): void {
        $token = "token with ' quote";

        $command = localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: 'internal:executor:verify',
            arguments: [],
            options: [],
            operationToken: $token,
        );

        expect($command)->toBe('/usr/local/bin/orbit internal:executor:verify --operation-token='.escapeshellarg($token).' --json')
            ->and($command)->toEndWith(' --json');
    });

    it('builds an audit line with the operation token redacted', function (): void {
        $auditLine = localExecutorCommandBuilder()->buildAuditLine(
            targetNode: localExecutorTargetNode(['app-dev']),
            commandName: 'internal:workspace-adapter:lookup',
            arguments: [],
            options: ['state-path' => '/home/orbit/.polyscope/polyscope.db'],
            operationToken: 'token-abc',
        );

        expect($auditLine)->toBe(implode(' ', [
            '/usr/local/bin/orbit',
            'internal:workspace-adapter:lookup',
            '--state-path='.escapeshellarg('/home/orbit/.polyscope/polyscope.db'),
            '--operation-token=<redacted>',
            '--json',
        ]))->not->toContain('token-abc');
    });

    it('rejects bad command names', function (string $commandName): void {
        expect(fn (): string => localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: $commandName,
            arguments: [],
            options: [],
            operationToken: 'token-abc',
        ))->toThrow(LocalExecutorCommandBuilderException::class);
    })->with([
        'empty' => '',
        'blank' => '   ',
        'missing internal namespace' => 'executor:verify',
        'missing command tail' => 'internal:',
        'uppercase' => 'internal:Executor:verify',
        'whitespace' => 'internal:executor verify',
        'path separator' => 'internal:executor/verify',
        'shell metacharacters' => 'evil; rm -rf /',
    ]);

    it('rejects command names outside the closed internal executor allow list', function (): void {
        expect(fn (): string => localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: 'internal:not-registered',
            arguments: [],
            options: [],
            operationToken: 'token-abc',
        ))->toThrow(LocalExecutorCommandBuilderException::class, 'not allowed');
    });

    it('exposes the complete closed role-scoped internal command allow list', function (): void {
        expect(LocalExecutorCommandBuilder::allowedCommandRoles())->toBe([
            'internal:executor:verify' => ['gateway', 'vpn', 'router', 'app-dev', 'app-prod', 'database', 'agent', 'ingress'],
            'internal:wg-easy:state' => ['vpn'],
            'internal:database-query-local' => ['app-dev', 'app-prod', 'database'],
            'internal:workspace-adapter:lookup' => ['app-dev'],
            'internal:workspace-adapter:update' => ['app-dev'],
        ]);
    });

    it('enforces role-scoped internal command allow-list entries :dataset', function (
        string $commandName,
        array $allowedRoles,
        array $rejectedRoles,
    ): void {
        expect(localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode($allowedRoles),
            commandName: $commandName,
            arguments: [],
            options: [],
            operationToken: 'token-abc',
        ))->toContain($commandName);

        expect(fn (): string => localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode($rejectedRoles),
            commandName: $commandName,
            arguments: [],
            options: [],
            operationToken: 'token-abc',
        ))->toThrow(LocalExecutorCommandBuilderException::class, 'not allowed');
    })->with([
        'executor verify' => ['internal:executor:verify', ['gateway'], []],
        'wg-easy state' => ['internal:wg-easy:state', ['vpn'], ['app-dev']],
        'database query local' => ['internal:database-query-local', ['database'], ['vpn']],
        'workspace adapter lookup' => ['internal:workspace-adapter:lookup', ['app-dev'], ['vpn']],
        'workspace adapter update' => ['internal:workspace-adapter:update', ['app-dev'], ['gateway']],
    ]);

    it('rejects non-scalar arguments', function (Closure $argumentFactory): void {
        $argument = $argumentFactory();

        try {
            expect(fn (): string => localExecutorCommandBuilder()->build(
                targetNode: localExecutorTargetNode(['gateway']),
                commandName: 'internal:executor:verify',
                arguments: [$argument],
                options: [],
                operationToken: 'token-abc',
            ))->toThrow(LocalExecutorCommandBuilderException::class);
        } finally {
            if (is_resource($argument)) {
                fclose($argument);
            }
        }
    })->with([
        'array' => [fn (): array => ['nested']],
        'object' => [fn (): stdClass => new stdClass],
        'null' => [fn (): null => null],
        'resource' => [fn () => fopen('php://temp', 'rb')],
    ]);

    it('rejects bad option keys', function (array $options): void {
        expect(fn (): string => localExecutorCommandBuilder()->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: 'internal:executor:verify',
            arguments: [],
            options: $options,
            operationToken: 'token-abc',
        ))->toThrow(LocalExecutorCommandBuilderException::class);
    })->with([
        'empty' => [['' => 'value']],
        'numeric' => [[0 => 'value']],
        'uppercase' => [['Bad' => 'value']],
        'underscore' => [['bad_key' => 'value']],
        'colon' => [['bad:key' => 'value']],
        'equals' => [['bad=value' => 'value']],
        'shell metacharacters' => [['bad;rm' => 'value']],
    ]);

    it('rejects non-scalar option values', function (Closure $valueFactory): void {
        $value = $valueFactory();

        try {
            expect(fn (): string => localExecutorCommandBuilder()->build(
                targetNode: localExecutorTargetNode(['gateway']),
                commandName: 'internal:executor:verify',
                arguments: [],
                options: ['state-path' => $value],
                operationToken: 'token-abc',
            ))->toThrow(LocalExecutorCommandBuilderException::class);
        } finally {
            if (is_resource($value)) {
                fclose($value);
            }
        }
    })->with([
        'array' => [fn (): array => ['nested']],
        'object' => [fn (): stdClass => new stdClass],
        'null' => [fn (): null => null],
        'resource' => [fn () => fopen('php://temp', 'rb')],
    ]);

    it('rejects null bytes in any input', function (Closure $build): void {
        expect(fn (): string => $build(localExecutorCommandBuilder()))
            ->toThrow(LocalExecutorCommandBuilderException::class);
    })->with([
        'command name' => [fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: "internal:executor\0verify",
            arguments: [],
            options: [],
            operationToken: 'token-abc',
        )],
        'argument' => [fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: 'internal:executor:verify',
            arguments: ["safe\0unsafe"],
            options: [],
            operationToken: 'token-abc',
        )],
        'option key' => [fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: 'internal:executor:verify',
            arguments: [],
            options: ["bad\0key" => 'value'],
            operationToken: 'token-abc',
        )],
        'option value' => [fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: 'internal:executor:verify',
            arguments: [],
            options: ['state-path' => "safe\0unsafe"],
            operationToken: 'token-abc',
        )],
        'operation token' => [fn (LocalExecutorCommandBuilder $builder): string => $builder->build(
            targetNode: localExecutorTargetNode(['gateway']),
            commandName: 'internal:executor:verify',
            arguments: [],
            options: [],
            operationToken: "token\0abc",
        )],
        'configured orbit binary' => [function (LocalExecutorCommandBuilder $builder): string {
            config()->set('orbit.local_executor_binary', "/usr/local/bin/orbit\0cli");

            return $builder->build(
                targetNode: localExecutorTargetNode(['gateway']),
                commandName: 'internal:executor:verify',
                arguments: [],
                options: [],
                operationToken: 'token-abc',
            );
        }],
    ]);
});

function localExecutorCommandBuilder(): LocalExecutorCommandBuilder
{
    return new LocalExecutorCommandBuilder;
}

function localExecutorTargetNode(array $roles = ['app-dev']): Node
{
    $node = new Node(['name' => 'target']);

    $assignments = array_map(
        fn (string $role): NodeRoleAssignment => new NodeRoleAssignment([
            'role' => $role,
            'status' => 'active',
        ]),
        $roles,
    );

    $node->setRelation('roleAssignments', new EloquentCollection($assignments));

    return $node;
}
