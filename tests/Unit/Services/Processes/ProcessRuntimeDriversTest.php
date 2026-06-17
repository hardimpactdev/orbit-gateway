<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessRuntimeDrivers\DockerProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\DockerSwarmProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\SystemdProcessRuntimeDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('runs docker process lifecycle through the docker runtime driver', function (): void {
    $shell = new ProcessRuntimeDriverRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()->forOwner($app)->create([
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'runtime' => ProcessRuntime::Docker,
    ]);

    $driver = app(DockerProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($runtimeUnit)->toBe('orbit_docs_main_queue')
        ->and($driver->start($node, $runtimeUnit))->toBeTrue()
        ->and($driver->stop($node, $runtimeUnit))->toBeTrue()
        ->and($driver->restart($node, $runtimeUnit))->toBeTrue()
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, false))
        ->toBe("docker logs --tail 25 'orbit_docs_main_queue' 2>&1")
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, true))
        ->toBe("docker logs --tail 25 --follow 'orbit_docs_main_queue' 2>&1");

    expect($shell->scripts)->toBe([
        "docker start 'orbit_docs_main_queue'",
        "docker stop 'orbit_docs_main_queue'",
        "docker restart 'orbit_docs_main_queue'",
    ]);
});

it('applies, removes, and cleans up docker process runtime units through the docker runtime driver', function (): void {
    $shell = new ProcessRuntimeDriverRecordingShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such network', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such container', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '{"Id":"abc"}', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->create(['name' => 'app-dev-1', 'user' => 'orbit']);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);
    $process = Process::factory()->forOwner($app)->create([
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'runtime' => ProcessRuntime::Docker,
    ]);

    $driver = app(DockerProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($driver->cleanupScript($runtimeUnit))
        ->toBe("docker rm -f 'orbit_docs_main_queue' 2>/dev/null || true")
        ->and($driver->apply($node, $app, $process))->toBeTrue()
        ->and($driver->remove($node, $runtimeUnit))->toBeTrue();

    expect(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, 'docker network inspect')))->toBeTrue()
        ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, 'docker network create')))->toBeTrue()
        ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, 'docker create')))->toBeTrue()
        ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, "docker rm -f 'orbit_docs_main_queue'")))->toBeTrue();
});

it('applies node owned docker service processes from runtime config', function (): void {
    $shell = new ProcessRuntimeDriverRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such network', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such container', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->create(['name' => 'database-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()->forOwner($node)->create([
        'name' => 'redis',
        'command' => 'redis-server --bind 0.0.0.0 --protected-mode no',
        'runtime' => ProcessRuntime::Docker,
        'runtime_config' => [
            'image' => 'redis:7.2',
            'environment' => [
                'ALLOW_EMPTY_PASSWORD' => 'yes',
            ],
            'mounts' => [
                [
                    'source' => '/var/lib/orbit/redis',
                    'target' => '/data',
                ],
            ],
            'network_aliases' => ['redis'],
        ],
    ]);

    $driver = app(DockerProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($runtimeUnit)->toBe('redis')
        ->and($driver->apply($node, $app, $process))->toBeTrue()
        ->and($driver->start($node, $runtimeUnit))->toBeTrue()
        ->and($driver->stop($node, $runtimeUnit))->toBeTrue()
        ->and($driver->restart($node, $runtimeUnit))->toBeTrue()
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, false))
        ->toBe("docker logs --tail 25 'redis' 2>&1");

    $create = collect($shell->scripts)->first(fn (string $script): bool => str_contains($script, 'docker create'));

    expect($create)->toBeString()
        ->toContain("--name 'redis'")
        ->toContain("--env 'ALLOW_EMPTY_PASSWORD=yes'")
        ->toContain("--mount 'type=bind,source=/var/lib/orbit/redis,target=/data'")
        ->toContain("'redis:7.2'")
        ->toContain("'-lc' 'redis-server --bind 0.0.0.0 --protected-mode no'");

    expect($shell->scripts)->toContain(
        "docker pull 'redis:7.2'",
        "sudo mkdir -p '/var/lib/orbit/redis'",
        "docker start 'redis'",
        "docker stop 'redis'",
        "docker restart 'redis'",
    );
});

it('runs docker swarm process lifecycle through the docker swarm runtime driver', function (): void {
    $shell = new ProcessRuntimeDriverRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->create(['name' => 'database-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()->forOwner($node)->create([
        'name' => 'redis7',
        'command' => 'redis-server --bind 0.0.0.0 --protected-mode no',
        'runtime' => ProcessRuntime::DockerSwarm,
        'runtime_config' => [
            'service_name' => 'orbit-redis-7',
        ],
    ]);

    $driver = app(DockerSwarmProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($runtimeUnit)->toBe('orbit-redis-7')
        ->and($driver->start($node, $runtimeUnit))->toBeTrue()
        ->and($driver->stop($node, $runtimeUnit))->toBeTrue()
        ->and($driver->restart($node, $runtimeUnit))->toBeTrue()
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, false))
        ->toBe("docker service logs --tail 25 'orbit-redis-7' 2>&1")
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, true))
        ->toBe("docker service logs --tail 25 --follow 'orbit-redis-7' 2>&1");

    expect($shell->scripts)->toBe([
        "docker service update --replicas 1 'orbit-redis-7'",
        "docker service update --replicas 0 'orbit-redis-7'",
        "docker service update --force 'orbit-redis-7'",
    ]);
});

it('applies, removes, and cleans up docker swarm process runtime services from runtime config', function (): void {
    $shell = new ProcessRuntimeDriverRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->create(['name' => 'database-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()->forOwner($node)->create([
        'name' => 'mysql8',
        'command' => 'mysqld',
        'restart_policy' => ProcessRestartPolicy::Always,
        'runtime' => ProcessRuntime::DockerSwarm,
        'runtime_config' => [
            'image' => 'mysql:8.4',
            'labels' => [
                'orbit.managed' => 'true',
                'orbit.process' => 'mysql8',
                'orbit.process.definition' => 'mysql',
                'orbit.process.spec_hash' => 'abc123',
                'orbit.process.version' => '8.4',
                'orbit.process.version_family' => '8',
            ],
            'ports' => [
                [
                    'published' => 3308,
                    'target' => 3306,
                    'protocol' => 'tcp',
                ],
            ],
            'bind_mounts' => [
                [
                    'source' => '/var/lib/orbit/processes/mysql8/mysql.cnf',
                    'target' => '/etc/mysql/conf.d/orbit.cnf',
                    'read_only' => true,
                ],
            ],
            'service_name' => 'orbit-mysql-8',
            'update_strategy' => [
                'order' => 'stop-first',
                'parallelism' => 1,
            ],
            'volumes' => [
                [
                    'name' => 'orbit-mysql-8',
                    'target' => '/var/lib/mysql',
                ],
            ],
        ],
    ]);

    $driver = app(DockerSwarmProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($driver->cleanupScript($runtimeUnit))
        ->toBe("docker service rm 'orbit-mysql-8' 2>/dev/null || true")
        ->and($driver->apply($node, $app, $process))->toBeTrue()
        ->and($driver->remove($node, $runtimeUnit))->toBeTrue();

    expect($shell->scripts[0])
        ->toContain("docker service inspect 'orbit-mysql-8' >/dev/null 2>&1")
        ->toContain('docker service create')
        ->toContain("--name 'orbit-mysql-8'")
        ->toContain("--restart-condition 'any'")
        ->toContain("--label 'orbit.process.definition=mysql'")
        ->toContain("--label 'orbit.process.spec_hash=abc123'")
        ->toContain("--publish 'published=3308,target=3306,protocol=tcp'")
        ->toContain("--mount 'type=volume,source=orbit-mysql-8,target=/var/lib/mysql'")
        ->toContain("--mount 'type=bind,source=/var/lib/orbit/processes/mysql8/mysql.cnf,target=/etc/mysql/conf.d/orbit.cnf,readonly'")
        ->toContain("--update-order 'stop-first'")
        ->toContain("--update-parallelism '1'")
        ->toContain("'mysql:8.4'")
        ->toContain("'-lc' 'mysqld'")
        ->and($shell->scripts[1])
        ->toBe("docker service rm 'orbit-mysql-8'");

    expect($shell->scripts[0])
        ->not->toContain("--restart-condition 'always'");
});

it('uses the image entrypoint for docker swarm service processes configured for it', function (): void {
    $shell = new ProcessRuntimeDriverRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->create(['name' => 'metrics-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()->forOwner($node)->create([
        'name' => 'grafana',
        'command' => '/run.sh',
        'restart_policy' => ProcessRestartPolicy::Always,
        'runtime' => ProcessRuntime::DockerSwarm,
        'runtime_config' => [
            'command_mode' => 'image_entrypoint',
            'image' => 'grafana/grafana:13.0.2',
            'service_name' => 'orbit-grafana',
        ],
    ]);

    $driver = app(DockerSwarmProcessRuntimeDriver::class);

    expect($driver->apply($node, $app, $process))->toBeTrue()
        ->and($shell->scripts[0])->toContain('docker service create')
        ->and($shell->scripts[0])->toContain("'grafana/grafana:13.0.2'")
        ->and($shell->scripts[0])->not->toContain("--entrypoint 'sh'")
        ->and($shell->scripts[0])->not->toContain("'-lc' '/run.sh'");
});

it('runs systemd process lifecycle through the systemd runtime driver', function (): void {
    $shell = new ProcessRuntimeDriverRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->create(['name' => 'app-dev-1', 'user' => 'orbit', 'orbit_path' => '/home/orbit/orbit']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()->forOwner($node)->create([
        'name' => 'opencode-server',
        'command' => 'opencode serve -a',
        'runtime' => ProcessRuntime::Systemd,
        'tool' => 'opencode',
    ]);

    $driver = app(SystemdProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($runtimeUnit)->toBe('opencode-server')
        ->and($driver->start($node, $runtimeUnit))->toBeTrue()
        ->and($driver->stop($node, $runtimeUnit))->toBeTrue()
        ->and($driver->restart($node, $runtimeUnit))->toBeTrue()
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, false))
        ->toBe("sudo journalctl -u 'opencode-server.service' -n 25 --no-pager --output=short-iso 2>&1")
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, true))
        ->toBe("sudo journalctl -u 'opencode-server.service' -n 25 -f --no-pager --output=short-iso 2>&1");

    expect($shell->scripts)->toBe([
        "sudo systemctl start 'opencode-server.service'",
        "sudo systemctl stop 'opencode-server.service'",
        "sudo systemctl restart 'opencode-server.service'",
    ]);
});

it('applies, removes, and cleans up systemd process runtime units through the systemd runtime driver', function (): void {
    $shell = new ProcessRuntimeDriverRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->create(['name' => 'app-dev-1', 'user' => 'orbit', 'orbit_path' => '/home/orbit/orbit']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()->forOwner($node)->create([
        'name' => 'opencode-server',
        'command' => 'opencode serve -a',
        'restart_policy' => ProcessRestartPolicy::OnFailure,
        'runtime' => ProcessRuntime::Systemd,
        'tool' => 'opencode',
    ]);

    $driver = app(SystemdProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($driver->cleanupScript($runtimeUnit))
        ->toBe("sudo systemctl stop 'opencode-server.service' >/dev/null 2>&1 || true; sudo systemctl disable 'opencode-server.service' >/dev/null 2>&1 || true; sudo rm -f '/etc/systemd/system/opencode-server.service'; sudo systemctl daemon-reload; sudo systemctl reset-failed 'opencode-server.service' >/dev/null 2>&1 || true; true")
        ->and($driver->apply($node, $app, $process))->toBeTrue()
        ->and($driver->remove($node, $runtimeUnit))->toBeTrue();

    expect($shell->scripts[0])
        ->toContain("sudo tee '/etc/systemd/system/opencode-server.service' >/dev/null")
        ->toContain('sudo systemctl daemon-reload')
        ->toContain("sudo systemctl enable 'opencode-server.service' >/dev/null")
        ->toContain('Description=Orbit process opencode-server')
        ->toContain('User=orbit')
        ->toContain('WorkingDirectory=/home/orbit')
        ->toContain("ExecStart=/bin/bash -lc 'opencode serve -a'")
        ->toContain('Restart=on-failure')
        ->and($shell->scripts[1])
        ->toContain("sudo systemctl stop 'opencode-server.service'")
        ->toContain("sudo systemctl disable 'opencode-server.service'")
        ->toContain("sudo rm -f '/etc/systemd/system/opencode-server.service'")
        ->toContain('sudo systemctl daemon-reload');
});

final class ProcessRuntimeDriverRecordingShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
        public array $scripts = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
