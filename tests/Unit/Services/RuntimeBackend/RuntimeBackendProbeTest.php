<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RuntimeBackend\RuntimeBackendProbe;

it('reports the runtime backend as available when systemd responds', function (): void {
    $node = new Node(['name' => 'app-1']);
    $remoteShell = new RuntimeBackendProbeRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: "systemd 255\n", stderr: '', durationMs: 1),
    );

    $result = (new RuntimeBackendProbe($remoteShell))->check($node);

    expect($result->available)->toBeTrue()
        ->and($result->exitCode)->toBe(0)
        ->and($result->output)->toBe('systemd 255')
        ->and($remoteShell->scripts)->toBe([
            'command -v systemctl >/dev/null 2>&1 && systemctl --version >/dev/null 2>&1',
        ])
        ->and($remoteShell->options[0]['timeout'])->toBe(15);
});

it('reports the runtime backend as unavailable when systemd is missing or unreachable', function (): void {
    $node = new Node(['name' => 'app-1']);
    $remoteShell = new RuntimeBackendProbeRecordingRemoteShell(
        new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'missing systemctl', durationMs: 1),
    );

    $result = (new RuntimeBackendProbe($remoteShell))->check($node);

    expect($result->available)->toBeFalse()
        ->and($result->exitCode)->toBe(127)
        ->and($result->output)->toBe('missing systemctl');
});

final class RuntimeBackendProbeRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    public function __construct(
        private readonly RemoteShellResult $result,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return $this->result;
    }
}
