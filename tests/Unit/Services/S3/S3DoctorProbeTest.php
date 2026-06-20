<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\S3\S3DoctorProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $overrides
 */
function s3ProbeNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 's3-node-1',
        'host' => 's3.example.com',
        'wireguard_address' => '10.6.0.20',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $settings
 */
function s3ProbeAssignment(Node $node, array $settings = ['data_path' => '/srv/orbit/s3/data']): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 's3',
        'status' => 'active',
        'settings' => $settings,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function s3ProbeTool(Node $node, array $overrides = []): NodeTool
{
    return NodeTool::factory()->create(array_merge([
        'node_id' => $node->id,
        'name' => 'seaweedfs',
        'expected_state' => 'installed',
        'config' => [],
        'credentials' => [
            'fields' => [
                'access_key_id' => 'test-key-id',
                'secret_access_key' => 'test-secret',
            ],
        ],
    ], $overrides));
}

/**
 * @param  list<RemoteShellResult|Throwable>  $results
 */
function s3ProbeShell(array $results = []): RemoteShell
{
    return new class($results) implements RemoteShell
    {
        /** @var list<string> */
        public array $scripts = [];

        /** @param list<RemoteShellResult|Throwable> $results */
        public function __construct(private array $results) {}

        /** @param array<string, mixed> $options */
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            $this->scripts[] = $script;
            $result = array_shift($this->results);

            if ($result instanceof Throwable) {
                throw $result;
            }

            return $result ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    };
}

function s3Probe(RemoteShell $shell): S3DoctorProbe
{
    return new S3DoctorProbe(
        remoteShell: $shell,
    );
}

// ---------------------------------------------------------------------------
// node family: node.s3.wireguard_missing
// ---------------------------------------------------------------------------

describe('s3 node drift — wireguard_missing', function (): void {
    it('emits node.s3.wireguard_missing when the s3 node has a null wireguard_address', function (): void {
        $node = s3ProbeNode(['wireguard_address' => null]);
        $assignment = s3ProbeAssignment($node);
        $shell = s3ProbeShell();

        $drift = s3Probe($shell)->nodeDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->toContain('node.s3.wireguard_missing');
    });

    it('emits node.s3.wireguard_missing when the s3 node has an empty wireguard_address', function (): void {
        $node = s3ProbeNode(['wireguard_address' => '']);
        $assignment = s3ProbeAssignment($node);
        $shell = s3ProbeShell();

        $drift = s3Probe($shell)->nodeDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->toContain('node.s3.wireguard_missing');
    });

    it('does not emit node.s3.wireguard_missing when the s3 node has a valid wireguard_address', function (): void {
        $node = s3ProbeNode(['wireguard_address' => '10.6.0.20']);
        $assignment = s3ProbeAssignment($node);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=true\npublished_address=10.6.0.20:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->nodeDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->not->toContain('node.s3.wireguard_missing');
    });

    it('sets the node.s3.wireguard_missing entry family to node and kind to Missing', function (): void {
        $node = s3ProbeNode(['wireguard_address' => null]);
        $assignment = s3ProbeAssignment($node);
        $shell = s3ProbeShell();

        $drift = s3Probe($shell)->nodeDrift($node, $assignment);

        $entry = collect($drift)->firstWhere('key', 'node.s3.wireguard_missing');
        expect($entry)->not->toBeNull()
            ->and($entry->family)->toBe('node')
            ->and($entry->kind)->toBe(DriftKind::Missing);
    });
});

// ---------------------------------------------------------------------------
// node family: node.s3_data_path_invalid
// ---------------------------------------------------------------------------

describe('s3 node drift — s3_data_path_invalid', function (): void {
    it('does not emit node.s3_data_path_invalid when data_path is absent (default path applied)', function (): void {
        // S3RoleSettings::fromArray([]) returns the default path — not invalid.
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node, []);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=true\npublished_address=10.6.0.20:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->nodeDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->not->toContain('node.s3_data_path_invalid');
    });

    it('emits node.s3_data_path_invalid when data_path is a relative path', function (): void {
        $node = s3ProbeNode();
        // S3RoleSettings throws on relative paths so we bypass it by injecting raw settings
        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 's3',
            'status' => 'active',
            'settings' => ['data_path' => 'relative/path'],
        ]);
        $shell = s3ProbeShell();

        $drift = s3Probe($shell)->nodeDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->toContain('node.s3_data_path_invalid');
    });

    it('does not emit node.s3_data_path_invalid when data_path is a valid absolute path', function (): void {
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node, ['data_path' => '/srv/orbit/s3/data']);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=true\npublished_address=10.6.0.20:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->nodeDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->not->toContain('node.s3_data_path_invalid');
    });

    it('sets the node.s3_data_path_invalid entry family to node and kind to Missing', function (): void {
        $node = s3ProbeNode();
        // Use a relative path to trigger the invalid setting check
        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 's3',
            'status' => 'active',
            'settings' => ['data_path' => 'relative/path'],
        ]);
        $shell = s3ProbeShell();

        $drift = s3Probe($shell)->nodeDrift($node, $assignment);

        $entry = collect($drift)->firstWhere('key', 'node.s3_data_path_invalid');
        expect($entry)->not->toBeNull()
            ->and($entry->family)->toBe('node')
            ->and($entry->kind)->toBe(DriftKind::Missing);
    });
});

// ---------------------------------------------------------------------------
// tool family: tool.seaweedfs.row_missing
// ---------------------------------------------------------------------------

describe('s3 tool drift — tool.seaweedfs.row_missing', function (): void {
    it('emits tool.seaweedfs.row_missing when no seaweedfs tool row exists on the s3 node', function (): void {
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node);
        // No tool row created
        $shell = s3ProbeShell();

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->toContain('tool.seaweedfs.row_missing');
    });

    it('does not emit tool.seaweedfs.row_missing when the seaweedfs tool row exists', function (): void {
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=true\npublished_address=10.6.0.20:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->not->toContain('tool.seaweedfs.row_missing');
    });

    it('sets the tool.seaweedfs.row_missing entry family to tool and kind to Missing', function (): void {
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node);
        $shell = s3ProbeShell();

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $entry = collect($drift)->firstWhere('key', 'tool.seaweedfs.row_missing');
        expect($entry)->not->toBeNull()
            ->and($entry->family)->toBe('tool')
            ->and($entry->kind)->toBe(DriftKind::Missing);
    });
});

// ---------------------------------------------------------------------------
// tool family: tool.seaweedfs.credentials_missing
// ---------------------------------------------------------------------------

describe('s3 tool drift — tool.seaweedfs.credentials_missing', function (): void {
    it('emits tool.seaweedfs.credentials_missing when the seaweedfs tool row has no credentials', function (): void {
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node, ['credentials' => null]);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=true\npublished_address=10.6.0.20:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->toContain('tool.seaweedfs.credentials_missing');
    });

    it('emits tool.seaweedfs.credentials_missing when credentials fields are incomplete (missing secret)', function (): void {
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node, ['credentials' => ['fields' => ['access_key_id' => 'key', 'secret_access_key' => '']]]);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=true\npublished_address=10.6.0.20:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->toContain('tool.seaweedfs.credentials_missing');
    });

    it('does not emit tool.seaweedfs.credentials_missing when both credential fields are present', function (): void {
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node, [
            'credentials' => ['fields' => ['access_key_id' => 'key-id', 'secret_access_key' => 'secret']],
        ]);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=true\npublished_address=10.6.0.20:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->not->toContain('tool.seaweedfs.credentials_missing');
    });

    it('sets the tool.seaweedfs.credentials_missing entry family to tool', function (): void {
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node, ['credentials' => null]);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=true\npublished_address=10.6.0.20:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $entry = collect($drift)->firstWhere('key', 'tool.seaweedfs.credentials_missing');
        expect($entry)->not->toBeNull()
            ->and($entry->family)->toBe('tool');
    });
});

// ---------------------------------------------------------------------------
// tool family: tool.seaweedfs.runtime_container_missing
// ---------------------------------------------------------------------------

describe('s3 tool drift — tool.seaweedfs.runtime_container_missing', function (): void {
    it('emits tool.seaweedfs.runtime_container_missing when the orbit-seaweedfs container does not exist', function (): void {
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=0\nrunning=false\npublished_address=\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->toContain('tool.seaweedfs.runtime_container_missing');
    });

    it('emits tool.seaweedfs.runtime_container_missing when the orbit-seaweedfs container is stopped', function (): void {
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=false\npublished_address=10.6.0.20:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->toContain('tool.seaweedfs.runtime_container_missing');
    });

    it('emits tool.seaweedfs.runtime_container_missing when the probe itself fails', function (): void {
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'docker: command not found', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->toContain('tool.seaweedfs.runtime_container_missing');
    });

    it('emits tool.seaweedfs.runtime_container_missing with Unverifiable kind when probe fails', function (): void {
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'docker: command not found', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $entry = collect($drift)->firstWhere('key', 'tool.seaweedfs.runtime_container_missing');
        expect($entry)->not->toBeNull()
            ->and($entry->kind)->toBe(DriftKind::Unverifiable);
    });

    it('does not emit tool.seaweedfs.runtime_container_missing when the container is running', function (): void {
        $node = s3ProbeNode();
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=true\npublished_address=10.6.0.20:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->not->toContain('tool.seaweedfs.runtime_container_missing');
    });
});

// ---------------------------------------------------------------------------
// tool family: tool.seaweedfs.bind_public_interface
// ---------------------------------------------------------------------------

describe('s3 tool drift — tool.seaweedfs.bind_public_interface', function (): void {
    it('emits tool.seaweedfs.bind_public_interface when the container published port is bound to 0.0.0.0', function (): void {
        $node = s3ProbeNode(['wireguard_address' => '10.6.0.20']);
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=true\npublished_address=0.0.0.0:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->toContain('tool.seaweedfs.bind_public_interface');
    });

    it('emits tool.seaweedfs.bind_public_interface when the container published port is bound to a public IP', function (): void {
        $node = s3ProbeNode(['wireguard_address' => '10.6.0.20']);
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=true\npublished_address=1.2.3.4:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->toContain('tool.seaweedfs.bind_public_interface');
    });

    it('does not emit tool.seaweedfs.bind_public_interface when the container is bound to the WireGuard address', function (): void {
        $node = s3ProbeNode(['wireguard_address' => '10.6.0.20']);
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=true\npublished_address=10.6.0.20:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $keys = array_column($drift, 'key');
        expect($keys)->not->toContain('tool.seaweedfs.bind_public_interface');
    });

    it('sets the tool.seaweedfs.bind_public_interface entry family to tool and kind to Divergent', function (): void {
        $node = s3ProbeNode(['wireguard_address' => '10.6.0.20']);
        $assignment = s3ProbeAssignment($node);
        s3ProbeTool($node);
        $shell = s3ProbeShell([
            new RemoteShellResult(exitCode: 0, stdout: "exists=1\nrunning=true\npublished_address=0.0.0.0:8333\n", stderr: '', durationMs: 1),
        ]);

        $drift = s3Probe($shell)->toolDrift($node, $assignment);

        $entry = collect($drift)->firstWhere('key', 'tool.seaweedfs.bind_public_interface');
        expect($entry)->not->toBeNull()
            ->and($entry->family)->toBe('tool')
            ->and($entry->kind)->toBe(DriftKind::Divergent);
    });
});
