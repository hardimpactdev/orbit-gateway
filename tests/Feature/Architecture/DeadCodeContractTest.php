<?php

declare(strict_types=1);

function deadCodeContractRepoRoot(): string
{
    $basePath = base_path();

    if (basename($basePath) === 'gateway' && basename(dirname($basePath)) === 'apps') {
        return dirname($basePath, 2);
    }

    return $basePath;
}

it('does not keep obsolete gateway-side stream client wrappers', function (): void {
    $repoRoot = deadCodeContractRepoRoot();

    expect("{$repoRoot}/apps/gateway/app/Http/Gateway/DeployRunGatewayStreamClient.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/WorkspaceNewGatewayStreamClient.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/WorkspaceSetupGatewayStreamClient.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/ToolActionGatewayStreamClient.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/Requests/Deploy/RunDeployStreamRequest.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/Requests/Workspaces/CreateWorkspaceStreamRequest.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/Requests/Workspaces/SetupWorkspaceStreamRequest.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/Requests/Tools/ToolActionStreamRequest.php")->not->toBeFile();
});

it('does not keep the unused remote progress reporter wrapper', function (): void {
    $repoRoot = deadCodeContractRepoRoot();

    expect("{$repoRoot}/apps/gateway/app/Support/Cli/RemoteProgressReporter.php")->not->toBeFile();
});
