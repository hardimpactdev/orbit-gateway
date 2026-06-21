<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Apps;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppsProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->probe = new AppsProbe;
});

describe('interface contract', function (): void {
    it('has key and label', function (): void {
        expect($this->probe->key())->toBe('app');
        expect($this->probe->label())->toBe('Apps');
    });

    it('returns empty snapshot from introspect', function (): void {
        $app = new App(['name' => 'site']);
        $snapshot = $this->probe->introspect($app);

        expect($snapshot->isEmpty())->toBeTrue();
    });
});

describe('docker-first probe', function (): void {
    it('renders a POSIX shell introspection script that does not depend on host PHP', function (): void {
        $node = appNode();
        $app = App::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'path' => '/home/orbit/apps/docs',
                'document_root' => 'public',
            ]);
        $shell = new AppsProbeRecordingRemoteShell("docs\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t0\n");

        (new AppsProbe($shell))->introspect($app);

        $script = $shell->scripts[0];

        expect($script)->not->toContain('php -r')
            ->and($script)->not->toContain('php-fpm')
            ->and($script)->toStartWith('set -eu')
            ->and($script)->toContain('docker container inspect')
            ->and($script)->toContain('command -v docker')
            ->and($shell->options[0])->not->toHaveKey('input');
    });

    it('passes runtime container identity through environment-assignment lines so the shell does not require STDIN input', function (): void {
        $node = appNode();
        $app = App::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'path' => '/home/orbit/apps/docs',
                'document_root' => 'web',
                'php_version' => '8.5',
            ]);
        $shell = new AppsProbeRecordingRemoteShell("docs\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t0\n");

        (new AppsProbe($shell))->introspect($app);

        expect($shell->scripts[0])
            ->toContain("APP_DOCUMENT_ROOT='web'")
            ->toContain("RUNTIME_CONTAINER_NAME='orbit-app-docs'");
    });
});

describe('source path and document root reality', function (): void {
    it('introspects source path, document root, and runtime container reality on the owning app node', function (): void {
        $node = appNode();
        $app = App::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'path' => '/home/orbit/apps/docs',
                'document_root' => 'public',
            ]);
        $shell = new AppsProbeRecordingRemoteShell("docs\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t0\n");

        $snapshot = (new AppsProbe($shell))->introspect($app);

        expect($snapshot->get('docs'))->toMatchArray([
            'path_exists' => true,
            'root_exists' => true,
            'root_inside_path' => true,
            'docker_available' => true,
            'container_exists' => true,
            'container_spec_matches' => true,
            'container_running' => true,
            'system_user_exists' => true,
            'fs_permissions_ok' => true,
        ]);
        expect($shell->scripts[0])->toContain('docker container inspect')
            ->and($shell->scripts[0])->toContain("APP_NAME='docs'")
            ->and($shell->scripts[0])->toContain("APP_PATH='/home/orbit/apps/docs'")
            ->and($shell->scripts[0])->toContain("APP_DOCUMENT_ROOT='public'")
            ->and($shell->scripts[0])->toContain("RUNTIME_KIND='php'")
            ->and($shell->scripts[0])->toContain("RUNTIME_CONTAINER_NAME='orbit-app-docs'");
        expect($shell->nodes[0]->is($node))->toBeTrue();
    });

    it('detects missing source paths', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => [
                'path_exists' => false,
                'root_exists' => false,
                'root_inside_path' => true,
            ],
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.path_missing')?->kind)->toBe(DriftKind::Missing);
        expect(issue($drift, 'app.root_missing'))->toBeNull();
    });

    it('detects missing document roots after the source path exists', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => [
                'path_exists' => true,
                'root_exists' => false,
                'root_inside_path' => true,
            ],
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.root_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects document roots that resolve outside the app path', function (): void {
        $node = appNode();
        $app = App::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'path' => '/home/orbit/apps/docs',
                'document_root' => '../shared/public',
            ]);

        $drift = (new AppsProbe)->diff($app, new ProbeSnapshot([]));

        expect(issue($drift, 'app.root_outside_path')?->kind)->toBe(DriftKind::Divergent);
    });
});

describe('PHP runtime reality', function (): void {
    it('detects unavailable Docker runtimes on the owning app node', function (): void {
        $node = appNode();
        $app = App::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'php_version' => '8.5',
            ]);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot(['docker_available' => false]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.php_version_unavailable')?->kind)->toBe(DriftKind::Missing);
    });

    it('does not report PHP runtime drift when the source path is missing', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'path_exists' => false,
                'root_exists' => false,
                'docker_available' => false,
            ]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.php_version_unavailable'))->toBeNull();
    });

    it('emits app.php_version_unavailable when the selected FrankenPHP image is not on the owning node', function (): void {
        $node = appNode();
        $app = App::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'php_version' => '8.5',
                'path' => '/home/orbit/apps/docs',
            ]);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot(['runtime_image_available' => false]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);
        $entry = issue($drift, 'app.php_version_unavailable');

        expect($entry?->kind)->toBe(DriftKind::Missing)
            ->and($entry?->detail['php_version'] ?? null)->toBe('8.5')
            ->and($entry?->detail['expected_image'] ?? null)->toBe('dunglas/frankenphp:1-php8.5-bookworm')
            ->and(issue($drift, 'app.runtime_container_missing'))->toBeNull()
            ->and(issue($drift, 'app.runtime_container_mismatch'))->toBeNull();
    });

    it('maps unknown image-probe failure with no existing container to documented app.runtime_container_missing (NOT app.php_version_unavailable, NOT a new probe-failed key)', function (): void {
        $node = appNode();
        $app = App::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'php_version' => '8.5',
                'path' => '/home/orbit/apps/docs',
            ]);

        // Image probe failed for unknown reason AND no container observed:
        // surface as the documented `app.runtime_container_missing` so doctor
        // restore re-attempts apply (which throws via the manager preflight
        // if the underlying issue persists). Must NOT emit
        // `app.php_version_unavailable` and must NOT introduce an undocumented
        // `app.runtime_image_probe_failed` key.
        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'runtime_image_available' => false,
                'runtime_image_probe_failed' => true,
                'container_exists' => false,
                'container_spec_matches' => false,
                'container_running' => false,
            ]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);
        $entry = issue($drift, 'app.runtime_container_missing');

        expect($entry?->kind)->toBe(DriftKind::Missing)
            ->and(issue($drift, 'app.php_version_unavailable'))->toBeNull()
            ->and(issue($drift, 'app.runtime_image_probe_failed'))->toBeNull()
            ->and(issue($drift, 'app.runtime_container_mismatch'))->toBeNull();
    });

    it('maps unknown image-probe failure with an existing-but-mismatched container to documented app.runtime_container_mismatch', function (): void {
        $node = appNode();
        $app = App::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'php_version' => '8.5',
                'path' => '/home/orbit/apps/docs',
            ]);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'runtime_image_available' => false,
                'runtime_image_probe_failed' => true,
                'container_exists' => true,
                'container_spec_matches' => false,
                'container_running' => true,
            ]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_container_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(issue($drift, 'app.php_version_unavailable'))->toBeNull()
            ->and(issue($drift, 'app.runtime_image_probe_failed'))->toBeNull()
            ->and(issue($drift, 'app.runtime_container_missing'))->toBeNull();
    });

    it('does not emit app.php_version_unavailable for static apps regardless of node image availability', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->static()->create(['name' => 'marketing']);

        $snapshot = new ProbeSnapshot([
            'marketing' => convergedRuntimeSnapshot(['runtime_image_available' => false]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.php_version_unavailable'))->toBeNull();
    });
});

describe('runtime container reality', function (): void {
    it('detects missing FrankenPHP runtime containers for PHP apps', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'container_exists' => false,
                'container_spec_matches' => false,
                'container_running' => false,
            ]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_container_missing')?->kind)->toBe(DriftKind::Missing);
        expect(issue($drift, 'app.runtime_container_mismatch'))->toBeNull();
    });

    it('detects FrankenPHP runtime container spec mismatches', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot(['container_spec_matches' => false]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_container_mismatch')?->kind)->toBe(DriftKind::Divergent);
    });

    it('does not report runtime container drift before Docker is available', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'docker_available' => false,
                'container_exists' => false,
                'container_spec_matches' => false,
            ]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_container_missing'))->toBeNull();
        expect(issue($drift, 'app.runtime_container_mismatch'))->toBeNull();
    });

    it('does not report runtime container drift for static apps', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->static()->create(['name' => 'marketing']);

        $snapshot = new ProbeSnapshot([
            'marketing' => convergedRuntimeSnapshot([
                'container_exists' => false,
                'container_spec_matches' => false,
            ]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_container_missing'))->toBeNull();
        expect(issue($drift, 'app.runtime_container_mismatch'))->toBeNull();
    });

    it('reports a stopped but otherwise matching FrankenPHP runtime container as app.runtime_container_missing because the endpoint is absent', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'container_exists' => true,
                'container_spec_matches' => true,
                'container_running' => false,
            ]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);
        $entry = issue($drift, 'app.runtime_container_missing');

        expect($entry?->kind)->toBe(DriftKind::Missing)
            ->and($entry?->detail['reason'] ?? null)->toBe('container_stopped')
            ->and(issue($drift, 'app.runtime_container_mismatch'))->toBeNull();
    });
});

describe('managed runtime config reality', function (): void {
    it('detects missing managed runtime config files for PHP apps', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'runtime_config_exists' => false,
                'runtime_config_matches' => false,
            ]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_config_missing')?->kind)->toBe(DriftKind::Missing);
        expect(issue($drift, 'app.runtime_config_mismatch'))->toBeNull();
    });

    it('detects managed runtime config hash mismatches', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot(['runtime_config_matches' => false]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_config_mismatch')?->kind)->toBe(DriftKind::Divergent);
    });

    it('does not report runtime config drift for static apps', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->static()->create(['name' => 'marketing']);

        $snapshot = new ProbeSnapshot([
            'marketing' => convergedRuntimeSnapshot([
                'runtime_config_exists' => false,
                'runtime_config_matches' => false,
            ]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_config_missing'))->toBeNull();
        expect(issue($drift, 'app.runtime_config_mismatch'))->toBeNull();
    });

    it('lists orphan /etc/orbit/apps/*.ini files via the node config scan when the directory probe is present', function (): void {
        $node = appNode();
        $shell = new AppsProbeRecordingRemoteShell("orbit-config-dir:present\n/etc/orbit/apps/docs.ini\n/etc/orbit/apps/marketing.ini\n");

        $probe = (new AppsProbe($shell))->introspectNodeRuntimeConfigs($node);

        expect($probe->status->value)->toBe('present')
            ->and($probe->error)->toBe('')
            ->and($probe->configs->keys())->toContain('docs')
            ->and($probe->configs->keys())->toContain('marketing')
            ->and($probe->configs->get('docs'))->toBe([
                'path' => '/etc/orbit/apps/docs.ini',
                'app_slug' => 'docs',
            ])
            ->and($shell->scripts[0])->toContain('sudo find "$dir"')
            ->and($shell->scripts[0])->toContain("dir='/etc/orbit/apps'");
    });

    it('reports the runtime config directory as proven-absent when sudo test -d exits 1 cleanly', function (): void {
        $node = appNode();
        $shell = new AppsProbeRecordingRemoteShell("orbit-config-dir:absent\n");

        $probe = (new AppsProbe($shell))->introspectNodeRuntimeConfigs($node);

        expect($probe->status->value)->toBe('absent')
            ->and($probe->error)->toBe('')
            ->and($probe->configs->isEmpty())->toBeTrue();
    });

    it('reports unknown sudo/probe failures distinctly (does NOT silently hide stale runtime_config_extra artifacts)', function (): void {
        $node = appNode();
        // Even if find emits artifact lines after an error sentinel, we must
        // not surface them — they are not trustworthy. The status carries
        // forward as Error and the configs snapshot is intentionally empty.
        $shell = new AppsProbeRecordingRemoteShell("orbit-config-dir:error sudo: a terminal is required to read the password\n/etc/orbit/apps/stale.ini\n");

        $probe = (new AppsProbe($shell))->introspectNodeRuntimeConfigs($node);

        expect($probe->status->value)->toBe('error')
            ->and($probe->error)->toContain('terminal')
            ->and($probe->configs->isEmpty())->toBeTrue();
    });

    it('reports as error (not clean empty) when sudo test -d succeeds but sudo find itself fails (would otherwise hide stale runtime_config_extra)', function (): void {
        $node = appNode();
        // Probe script must distinguish a successful directory check followed
        // by a FAILING find from a successful directory check with no entries.
        // The shell emits `orbit-config-dir:error <stderr>` so the orchestrator
        // does not treat this as a clean empty snapshot.
        $shell = new AppsProbeRecordingRemoteShell("orbit-config-dir:error find: '/etc/orbit/apps': Permission denied\n");

        $probe = (new AppsProbe($shell))->introspectNodeRuntimeConfigs($node);

        expect($probe->status->value)->toBe('error')
            ->and($probe->error)->toContain('Permission denied')
            ->and($probe->configs->isEmpty())->toBeTrue();
    });

    it('captures both find stdout and find exit status so a non-zero find exit becomes error even without stderr text', function (): void {
        $node = appNode();
        // Simulate: shell emits error sentinel because find exited non-zero
        // with no stderr text (the script falls back to a synthesized message).
        $shell = new AppsProbeRecordingRemoteShell("orbit-config-dir:error sudo find failed (ec=2)\n");

        $probe = (new AppsProbe($shell))->introspectNodeRuntimeConfigs($node);

        expect($probe->status->value)->toBe('error')
            ->and($probe->error)->toContain('sudo find failed')
            ->and($probe->configs->isEmpty())->toBeTrue();
    });

    it('reports Error status (no clean absence) when the remote shell call itself throws — SSH/transport failure must not abort doctor', function (): void {
        $node = appNode();
        $shell = new AppsProbeThrowingRemoteShell('ssh: connect to host: connection refused');

        $probe = (new AppsProbe($shell))->introspectNodeRuntimeConfigs($node);

        expect($probe->status->value)->toBe('error')
            ->and($probe->error)->toContain('connection refused')
            ->and($probe->configs->isEmpty())->toBeTrue();
    });

    it('reports Error status (no clean absence) when the remote shell returns a non-zero exit code without a sentinel', function (): void {
        $node = appNode();
        $shell = new AppsProbeFailingRemoteShell(exitCode: 255, stderr: 'remote shell pipeline broke');

        $probe = (new AppsProbe($shell))->introspectNodeRuntimeConfigs($node);

        expect($probe->status->value)->toBe('error')
            ->and($probe->error)->toContain('remote shell pipeline broke')
            ->and($probe->configs->isEmpty())->toBeTrue();
    });

    it('renders an introspect script that captures sudo find exit status and stderr separately and only emits `orbit-config-dir:present` after find succeeds', function (): void {
        $node = appNode();
        $shell = new AppsProbeRecordingRemoteShell("orbit-config-dir:absent\n");

        (new AppsProbe($shell))->introspectNodeRuntimeConfigs($node);

        $script = $shell->scripts[0];

        expect($script)->toContain("dir='/etc/orbit/apps'")
            ->and($script)->toContain('sudo test -d')
            ->and($script)->toContain('sudo find')
            // The script must capture the find exit code separately so a
            // successful test -d followed by a failing find still surfaces
            // through the error sentinel.
            ->and($script)->toContain('list_ec=$?')
            ->and($script)->toContain('orbit-config-dir:error')
            ->and($script)->toContain('orbit-config-dir:present')
            ->and($script)->toContain('orbit-config-dir:absent');
    });
});

describe('production security reality', function (): void {
    it('detects production app runtime container isolation drift', function (): void {
        $node = appNode([], role: 'app-prod');
        $app = App::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'environment' => 'production',
                'path' => '/home/orbit/apps/docs',
            ]);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'container_exists' => false,
                'container_spec_matches' => false,
                'system_user_exists' => false,
                'fs_permissions_ok' => false,
            ]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(issue($drift, 'app.security.system_user')?->kind)->toBe(DriftKind::Missing)
            ->and(issue($drift, 'app.security.fs_permissions')?->kind)->toBe(DriftKind::Divergent)
            ->and(issue($drift, 'app.security.runtime_container_isolation')?->kind)->toBe(DriftKind::Missing);
    });

    it('does not apply production app security drift to development apps', function (): void {
        $node = appNode();
        $app = App::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
            ]);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'system_user_exists' => false,
                'fs_permissions_ok' => false,
                'container_exists' => false,
                'container_spec_matches' => false,
            ]),
        ]);

        $drift = (new AppsProbe)->diff($app, $snapshot);

        expect(collect($drift)->pluck('key')->filter(fn (string $key): bool => str_starts_with($key, 'app.security.'))->all())
            ->toBe([]);
    });
});

describe('registry intent', function (): void {
    it('passes complete app records on active app nodes', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->create();

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('detects incomplete app records', function (): void {
        $node = appNode();

        $id = DB::table('apps')->insertGetId([
            'name' => 'incomplete',
            'node_id' => $node->id,
            'environment' => '',
            'path' => '',
            'document_root' => 'public',
            'php_version' => '8.5',
            'adopted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $app = App::findOrFail($id);

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));

        expect($drift)->toHaveCount(1);
        expect($drift[0]->family)->toBe('app');
        expect($drift[0]->key)->toBe('app.record_incomplete');
        expect($drift[0]->kind)->toBe(DriftKind::Missing);
    });
});

describe('owning node eligibility', function (): void {
    it('requires an active app node owner', function (callable $createNode): void {
        $node = $createNode();
        $app = App::factory()->for($node, 'node')->create();

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));
        $ownerIssues = array_values(array_filter(
            $drift,
            fn (DriftEntry $entry): bool => $entry->key === 'app.owner_node_invalid',
        ));

        expect($ownerIssues)->toHaveCount(1);
        expect($ownerIssues[0]->kind)->toBe(DriftKind::Divergent);
    })->with([
        'gateway owner' => [fn (): Node => Node::factory()->gateway()->create(['status' => 'active'])],
        'inactive app owner' => [fn (): Node => Node::factory()->appDev()->create(['status' => 'inactive'])],
    ]);

    it('accepts active app node owners', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->create();

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));
        $ownerIssues = array_filter(
            $drift,
            fn (DriftEntry $entry): bool => $entry->key === 'app.owner_node_invalid',
        );

        expect($ownerIssues)->toHaveCount(0);
    });
});

describe('app agent IDE defaults', function (): void {
    it('detects unsupported app agent IDE adapters', function (): void {
        $node = appNode();
        $app = App::factory()
            ->for($node, 'node')
            ->create(['agent_ide_config' => ['adapter' => 'unsupported']]);

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));
        $adapterIssues = array_values(array_filter(
            $drift,
            fn (DriftEntry $entry): bool => $entry->key === 'app.agent_ide_default_invalid',
        ));

        expect($adapterIssues)->toHaveCount(1);
        expect($adapterIssues[0]->kind)->toBe(DriftKind::Divergent);
    });

    it('accepts supported app agent IDE adapters', function (?array $agentIdeConfig): void {
        $node = appNode();
        $app = App::factory()
            ->for($node, 'node')
            ->create(['agent_ide_config' => $agentIdeConfig]);

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));
        $adapterIssues = array_filter(
            $drift,
            fn (DriftEntry $entry): bool => $entry->key === 'app.agent_ide_default_invalid',
        );

        expect($adapterIssues)->toHaveCount(0);
    })->with([
        'inherited default' => [null],
        'disabled' => [['adapter' => 'none']],
        'opencode' => [['adapter' => 'opencode']],
        'polyscope' => [['adapter' => 'polyscope']],
    ]);
});

function issue(array $drift, string $key): ?DriftEntry
{
    return collect($drift)->first(fn (DriftEntry $entry): bool => $entry->key === $key);
}

describe('extra runtime container scan', function (): void {
    it('lists every orbit-owned app runtime container on the node by label when the scan succeeds (Present status)', function (): void {
        $node = appNode();
        $shell = new AppsProbeRecordingRemoteShell("orbit-container-scan:present\norbit-app-docs\tdocs\norbit-app-marketing\tmarketing\n");

        $probe = (new AppsProbe($shell))->introspectNode($node);

        expect($probe->status->value)->toBe('present')
            ->and($probe->error)->toBe('')
            ->and($probe->containers->keys())->toContain('docs')
            ->and($probe->containers->keys())->toContain('marketing')
            ->and($probe->containers->get('docs'))->toMatchArray([
                'container_name' => 'orbit-app-docs',
                'app_slug' => 'docs',
            ])
            ->and($shell->scripts[0])->toContain('docker container ls')
            ->and($shell->scripts[0])->toContain('orbit.managed=true')
            ->and($shell->scripts[0])->toContain('orbit.container.kind=app-runtime');
    });

    it('reports Absent status when docker is not installed on the node (no Orbit-managed runtime containers can exist)', function (): void {
        $node = appNode();
        $shell = new AppsProbeRecordingRemoteShell("orbit-container-scan:absent\n");

        $probe = (new AppsProbe($shell))->introspectNode($node);

        expect($probe->status->value)->toBe('absent')
            ->and($probe->error)->toBe('')
            ->and($probe->containers->isEmpty())->toBeTrue();
    });

    it('reports Error status with the docker stderr when docker container ls fails for an unknown reason (does NOT silently hide stale runtime_container_extra artifacts)', function (): void {
        $node = appNode();
        // Even if container entries appear after an error sentinel, they
        // must not be surfaced — the status carries forward as Error and
        // the snapshot is intentionally empty.
        $shell = new AppsProbeRecordingRemoteShell("orbit-container-scan:error Cannot connect to the Docker daemon at unix:///var/run/docker.sock\norbit-app-stale\tstale\n");

        $probe = (new AppsProbe($shell))->introspectNode($node);

        expect($probe->status->value)->toBe('error')
            ->and($probe->error)->toContain('Cannot connect to the Docker daemon')
            ->and($probe->containers->isEmpty())->toBeTrue();
    });

    it('reports Error status when the remote shell call itself throws (SSH/transport failure must not abort doctor)', function (): void {
        $node = appNode();
        $shell = new AppsProbeThrowingRemoteShell('ssh: connect to host: connection refused');

        $probe = (new AppsProbe($shell))->introspectNode($node);

        expect($probe->status->value)->toBe('error')
            ->and($probe->error)->toContain('connection refused')
            ->and($probe->containers->isEmpty())->toBeTrue();
    });

    it('reports Error status when the remote shell returns a non-zero exit code without a sentinel', function (): void {
        $node = appNode();
        $shell = new AppsProbeFailingRemoteShell(exitCode: 255, stderr: 'remote shell pipeline broke');

        $probe = (new AppsProbe($shell))->introspectNode($node);

        expect($probe->status->value)->toBe('error')
            ->and($probe->error)->toContain('remote shell pipeline broke')
            ->and($probe->containers->isEmpty())->toBeTrue();
    });

    it('skips lines without an orbit.app label inside a Present scan', function (): void {
        $node = appNode();
        $shell = new AppsProbeRecordingRemoteShell("orbit-container-scan:present\norbit-app-docs\tdocs\nbroken-line\t\n");

        $probe = (new AppsProbe($shell))->introspectNode($node);

        expect($probe->status->value)->toBe('present')
            ->and($probe->containers->keys())->toBe(['docs']);
    });
});

function convergedRuntimeSnapshot(array $overrides = []): array
{
    return [
        'path_exists' => true,
        'root_exists' => true,
        'root_inside_path' => true,
        'docker_available' => true,
        'container_exists' => true,
        'container_spec_matches' => true,
        'container_running' => true,
        'system_user_exists' => true,
        'fs_permissions_ok' => true,
        'runtime_config_exists' => true,
        'runtime_config_matches' => true,
        'runtime_image_available' => true,
        'runtime_image_probe_failed' => false,
        ...$overrides,
    ];
}

function appNode(array $overrides = [], string $role = 'app-dev'): Node
{
    return createTestAppHostNode([
        ...$overrides,
    ], role: $role);
}

final class AppsProbeRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<Node>
     */
    public array $nodes = [];

    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    public function __construct(private readonly string $stdout) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;
        $this->options[] = $options;

        return new RemoteShellResult(
            exitCode: 0,
            stdout: $this->stdout,
            stderr: '',
            durationMs: 1,
        );
    }
}

final class AppsProbeThrowingRemoteShell implements RemoteShell
{
    public function __construct(private readonly string $message) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new \RuntimeException($this->message);
    }
}

final class AppsProbeFailingRemoteShell implements RemoteShell
{
    public function __construct(
        private readonly int $exitCode,
        private readonly string $stderr,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(
            exitCode: $this->exitCode,
            stdout: '',
            stderr: $this->stderr,
            durationMs: 1,
        );
    }
}
