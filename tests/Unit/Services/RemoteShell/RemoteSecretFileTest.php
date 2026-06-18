<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteSecretFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('stages secrets through stdin and removes the remote file after use', function (): void {
    $node = Node::factory()->create();
    $shell = new RemoteSecretFileRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: "/tmp/orbit-secret.abcd\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $path = (new RemoteSecretFile($shell))->stage($node, 'super-secret-token', function (string $path): string {
        return $path;
    });

    expect($path)->toBe('/tmp/orbit-secret.abcd')
        ->and($shell->scripts[0])->toContain('mktemp')
        ->and($shell->scripts[0])->not->toContain('super-secret-token')
        ->and($shell->options[0]['input'])->toBe(base64_encode('super-secret-token'))
        ->and($shell->scripts[1])->toBe("rm -f '/tmp/orbit-secret.abcd'");
});

it('removes the remote secret file when the callback fails', function (): void {
    $node = Node::factory()->create();
    $shell = new RemoteSecretFileRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: "/tmp/orbit-secret.failed\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    expect(fn () => (new RemoteSecretFile($shell))->stage($node, 'secret', function (): never {
        throw new RuntimeException('callback failed');
    }))->toThrow(RuntimeException::class, 'callback failed');

    expect($shell->scripts[1])->toBe("rm -f '/tmp/orbit-secret.failed'");
});

final class RemoteSecretFileRecordingShell implements RemoteShell
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
