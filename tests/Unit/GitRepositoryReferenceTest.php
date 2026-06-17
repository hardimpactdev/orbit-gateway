<?php

declare(strict_types=1);

use App\Support\GitRepositoryReference;

it('uses gh for github clones and reuses an existing github checkout', function (): void {
    $script = GitRepositoryReference::cloneCommand(
        'git@github.com:hardimpactdev/hauser.git',
        '/home/nckrtl/apps/hauser',
    );

    expect($script)
        ->toContain("gh repo clone 'hardimpactdev/hauser' '/home/nckrtl/apps/hauser'")
        ->toContain("test -d '/home/nckrtl/apps/hauser'")
        ->toContain("git -C '/home/nckrtl/apps/hauser' remote get-url origin")
        ->toContain("'git@github.com:hardimpactdev/hauser.git'|'https://github.com/hardimpactdev/hauser.git'|'ssh://git@github.com/hardimpactdev/hauser.git'");
});

it('uses git for non github clones and reuses an existing matching checkout', function (): void {
    $script = GitRepositoryReference::cloneCommand(
        'https://gitlab.com/acme/api.git',
        '/home/deploy/apps/api',
    );

    expect($script)
        ->toContain("git clone 'https://gitlab.com/acme/api.git' '/home/deploy/apps/api'")
        ->toContain("test -d '/home/deploy/apps/api'")
        ->toContain("git -C '/home/deploy/apps/api' remote get-url origin")
        ->toContain("'https://gitlab.com/acme/api.git'");
});
