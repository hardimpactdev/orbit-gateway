<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;

it('reports success and combines output streams', function (): void {
    $result = new RemoteShellResult(
        exitCode: 0,
        stdout: "created\n",
        stderr: "warning\n",
        durationMs: 12,
    );

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("created\nwarning\n");
});

it('omits empty output streams from combined output', function (): void {
    $result = new RemoteShellResult(
        exitCode: 1,
        stdout: '',
        stderr: "failed\n",
        durationMs: 8,
    );

    expect($result->successful())->toBeFalse()
        ->and($result->output())->toBe("failed\n");
});
