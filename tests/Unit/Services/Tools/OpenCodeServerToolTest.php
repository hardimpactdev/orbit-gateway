<?php

declare(strict_types=1);

use App\Tools\OpenCodeServerTool;

it('installs only the server binary because lifecycle belongs to a process', function (): void {
    $script = (new OpenCodeServerTool)->installScript();

    expect($script)
        ->toContain('https://opencode.ai/install')
        ->not->toContain('supervisorctl')
        ->not->toContain('/etc/supervisor')
        ->not->toContain('systemctl')
        ->not->toContain('loginctl')
        ->not->toContain('.config/systemd/user');
});

it('keeps lifecycle metadata out of the tool definition', function (): void {
    $tool = new OpenCodeServerTool;
    $metadata = $tool->probeMetadata();

    expect($tool->removeScript())
        ->toContain('rm -rf "${home}/.opencode"')
        ->not->toContain('supervisorctl')
        ->not->toContain('systemctl')
        ->and($tool->updateScript())
        ->toContain('"${home}/.opencode/bin/opencode" upgrade')
        ->not->toContain('supervisorctl')
        ->not->toContain('systemctl')
        ->and($tool->reconfigureScript())
        ->toContain('tool capability config and credentials')
        ->not->toContain('supervisorctl')
        ->not->toContain('systemctl')
        ->and($metadata)
        ->toMatchArray([
            'binary' => 'opencode',
        ])
        ->and($metadata)->not->toHaveKey('supervisor_program')
        ->and($metadata)->not->toHaveKey('supervisor_log')
        ->and($metadata)->not->toHaveKey('repair_commands');
});
