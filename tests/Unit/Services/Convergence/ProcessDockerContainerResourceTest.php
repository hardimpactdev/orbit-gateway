<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Convergence\ConvergenceStatus;
use App\Enums\Processes\ProcessDockerContainerApplyOutcome;
use App\Models\Node;
use App\Services\Convergence\ProcessDockerContainerResource;
use App\Services\Processes\ProcessDockerContainer;
use App\Services\Runtime\DockerCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('plans ok when the docker process container already matches gateway intent', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $container = processDockerContainerResourceContainer();
    $resource = new ProcessDockerContainerResource($container, new DockerCommandBuilder);
    $shell = new ProcessDockerContainerResourceShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'Config' => [
                    'Labels' => [
                        ProcessDockerContainer::SpecHashLabel => $container->specHash(),
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        ),
    ]);

    $probe = $resource->probe($node, $shell);
    $plan = $resource->plan($probe);
    $result = $resource->apply($node, $shell, $plan);

    expect($probe->exists)->toBeTrue()
        ->and($probe->specHash)->toBe($container->specHash())
        ->and($plan->status)->toBe(ConvergenceStatus::Ok)
        ->and($plan->outcome)->toBe(ProcessDockerContainerApplyOutcome::Unchanged)
        ->and($result->status)->toBe(ConvergenceStatus::Ok)
        ->and($result->changed())->toBeFalse()
        ->and($shell->scripts)->toHaveCount(1)
        ->and($shell->scripts[0])->toBe("docker container inspect --format '{{json .}}' 'orbit_docs_main_queue'");
});

it('ensures the docker network before creating a missing idle process container', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $container = processDockerContainerResourceContainer();
    $resource = new ProcessDockerContainerResource($container, new DockerCommandBuilder);
    $shell = new ProcessDockerContainerResourceShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such network', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such container', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $resource->ensureNetwork($node, $shell);
    $probe = $resource->probe($node, $shell);
    $plan = $resource->plan($probe);
    $result = $resource->apply($node, $shell, $plan);

    expect($probe->exists)->toBeFalse()
        ->and($plan->status)->toBe(ConvergenceStatus::Changed)
        ->and($plan->outcome)->toBe(ProcessDockerContainerApplyOutcome::Created)
        ->and($result->status)->toBe(ConvergenceStatus::Changed)
        ->and($result->details['outcome'])->toBe('created')
        ->and($shell->scripts[0])->toBe("docker network inspect 'orbit-network'")
        ->and($shell->scripts[1])->toBe("docker network create --label 'orbit.managed=true' --label 'orbit.network.kind=runtime' 'orbit-network'")
        ->and($shell->scripts[2])->toBe("docker container inspect --format '{{json .}}' 'orbit_docs_main_queue'")
        ->and($shell->scripts[3])->toStartWith('docker create')
        ->and($shell->scripts[3])->toContain("--name 'orbit_docs_main_queue'")
        ->and($shell->scripts[3])->not->toContain('docker run -d')
        ->and($shell->scripts[3])->not->toContain('docker start');
});

it('removes and recreates a docker process container when the spec hash drifts', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $container = processDockerContainerResourceContainer();
    $resource = new ProcessDockerContainerResource($container, new DockerCommandBuilder);
    $shell = new ProcessDockerContainerResourceShell([
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
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $probe = $resource->probe($node, $shell);
    $plan = $resource->plan($probe);
    $result = $resource->apply($node, $shell, $plan);

    expect($plan->status)->toBe(ConvergenceStatus::Changed)
        ->and($plan->outcome)->toBe(ProcessDockerContainerApplyOutcome::Recreated)
        ->and($plan->details['observed_hash'])->toBe('old-hash')
        ->and($result->status)->toBe(ConvergenceStatus::Changed)
        ->and($result->details['outcome'])->toBe('recreated')
        ->and($shell->scripts[1])->toBe("docker rm -f 'orbit_docs_main_queue'")
        ->and($shell->scripts[2])->toStartWith('docker create');
});

it('returns a failed apply result when creating the docker process container fails', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $container = processDockerContainerResourceContainer();
    $resource = new ProcessDockerContainerResource($container, new DockerCommandBuilder);
    $shell = new ProcessDockerContainerResourceShell([
        new RemoteShellResult(exitCode: 9, stdout: '', stderr: 'image missing', durationMs: 1),
    ]);

    $result = $resource->apply(
        $node,
        $shell,
        $resource->plan($resource->probe($node, new ProcessDockerContainerResourceShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such container', durationMs: 1),
        ]))),
    );

    expect($result->status)->toBe(ConvergenceStatus::Failed)
        ->and($result->summary)->toBe('Failed to create orbit_docs_main_queue container on app-dev-1: image missing')
        ->and($result->successful())->toBeFalse()
        ->and($result->details)->toMatchArray([
            'container' => 'orbit_docs_main_queue',
            'network' => 'orbit-network',
            'outcome' => 'created',
            'exit_code' => 9,
            'error' => 'image missing',
        ]);
});

function processDockerContainerResourceContainer(): ProcessDockerContainer
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

final class ProcessDockerContainerResourceShell implements RemoteShell
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
