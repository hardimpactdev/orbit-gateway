<?php

declare(strict_types=1);

use App\Services\RemoteShell\Exceptions\HostKeyMismatch;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

it('pins the preferred ssh host key in tofu mode', function (): void {
    Process::fake([
        'ssh-keyscan*' => Process::result(output: implode("\n", [
            '203.0.113.10 ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQCtest',
            '203.0.113.10 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests',
            '',
        ])),
    ]);
    Process::preventStrayProcesses();

    $key = app(SshHostKeyPinner::class)->pin('203.0.113.10');

    expect($key->host)->toBe('203.0.113.10')
        ->and($key->type)->toBe('ssh-ed25519')
        ->and($key->publicKey)->toBe('AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests')
        ->and($key->fingerprint)->toStartWith('SHA256:')
        ->and($key->pinMode)->toBe('tofu');
});

it('marks the pin verified when the expected fingerprint matches', function (): void {
    $publicKey = 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests';
    $expected = SshHostKeyPinner::fingerprintForPublicKey($publicKey);

    Process::fake([
        'ssh-keyscan*' => Process::result(output: "203.0.113.10 ssh-ed25519 {$publicKey}\n"),
    ]);
    Process::preventStrayProcesses();

    $key = app(SshHostKeyPinner::class)->pin('203.0.113.10', $expected);

    expect($key->fingerprint)->toBe($expected)
        ->and($key->pinMode)->toBe('verified');
});

it('retries transient empty ssh-keyscan failures before pinning', function (): void {
    $publicKey = 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests';

    Process::fake([
        'ssh-keyscan*' => Process::sequence()
            ->push(Process::result(exitCode: 1))
            ->push(Process::result(output: "203.0.113.10 ssh-ed25519 {$publicKey}\n")),
    ]);
    Process::preventStrayProcesses();

    $key = app(SshHostKeyPinner::class)->pin('203.0.113.10');

    expect($key->publicKey)->toBe($publicKey)
        ->and($key->pinMode)->toBe('tofu');
});

it('fails closed when the expected fingerprint does not match', function (): void {
    Process::fake([
        'ssh-keyscan*' => Process::result(output: "203.0.113.10 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests\n"),
    ]);
    Process::preventStrayProcesses();

    expect(fn () => app(SshHostKeyPinner::class)->pin('203.0.113.10', 'SHA256:not-the-key'))
        ->toThrow(HostKeyMismatch::class);
});
