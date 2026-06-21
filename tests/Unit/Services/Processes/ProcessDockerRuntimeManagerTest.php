<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessDockerContainerApplyOutcome;
use App\Exceptions\ProcessDockerContainerApplyException;
use App\Models\Node;
use App\Services\Processes\ProcessDockerContainer;
use App\Services\Processes\ProcessDockerRuntimeManager;
use App\Services\Runtime\DockerCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('converges a missing docker process container through the convergence resource path', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $container = processDockerRuntimeManagerContainer();
    $shell = new ProcessDockerRuntimeManagerShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such network', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such container', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $outcome = new ProcessDockerRuntimeManager($shell, new DockerCommandBuilder)
        ->apply($node, $container);

    expect($outcome)->toBe(ProcessDockerContainerApplyOutcome::Created)
        ->and($shell->scripts[0])->toBe("docker network inspect 'orbit-network'")
        ->and($shell->scripts[1])->toBe("docker network create --label 'orbit.managed=true' --label 'orbit.network.kind=runtime' 'orbit-network'")
        ->and($shell->scripts[2])->toBe("docker container inspect --format '{{json .}}' 'orbit_docs_main_queue'")
        ->and($shell->scripts[3])->toStartWith('docker create')
        ->and($shell->scripts[3])->not->toContain('docker start')
        ->and($shell->options)->toBe([
            ['throw' => false],
            ['throw' => false],
            ['throw' => false],
            ['throw' => false],
        ]);
});

it('wraps docker process container apply failures with the existing had-existing flag', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $container = processDockerRuntimeManagerContainer();
    $shell = new ProcessDockerRuntimeManagerShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'Config' => [
                    'Labels' => [
                        ProcessDockerContainer::SpecHashLabel => 'old-hash',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
    ]);

    try {
        new ProcessDockerRuntimeManager($shell, new DockerCommandBuilder)
            ->apply($node, $container);
    } catch (ProcessDockerContainerApplyException $exception) {
        expect($exception->hadExistingContainer)->toBeTrue()
            ->and($exception->getMessage())->toBe('Failed to remove drifted orbit_docs_main_queue container on app-dev-1: permission denied');

        return;
    }

    $this->fail('Expected process Docker container apply exception was not thrown.');
});

function processDockerRuntimeManagerContainer(): ProcessDockerContainer
{
    return new ProcessDockerContainer(
        name: 'orbit_docs_main_queue',
        image: 'dunglas/frankenphp:1-php8.5-bookworm',
        network: 'orbit-network',
        restartPolicy: 'always',
        appSlug: 'docs',
        workspaceSlug: null,
        processSlug: 'queue',
        workingDirectory: ProcessDockerContainer::SourceTarget,
        command: 'php artisan queue:work',
        environment: [
            'APP_URL' => 'https://docs.orbit.test',
            'ORBIT_APP' => 'docs',
        ],
        mounts: [
            [
                'source' => '/srv/docs',
                'target' => ProcessDockerContainer::SourceTarget,
                'read_only' => false,
            ],
        ],
        networkAliases: ['orbit_docs_main_queue'],
    );
}

final class ProcessDockerRuntimeManagerShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(1, '', 'unexpected call', 1);
    }
}
