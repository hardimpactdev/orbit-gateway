<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeDrivers;

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;

interface ProcessRuntimeDriver
{
    public function runtimeUnitName(App $app, Process $process, ?Workspace $workspace = null): string;

    public function apply(Node $node, App $app, Process $process, ?Workspace $workspace = null, ?string $preApplyScript = null): bool;

    public function remove(Node $node, string $runtimeUnit): bool;

    public function cleanupScript(string $runtimeUnit): string;

    public function start(Node $node, string $runtimeUnit): bool;

    public function stop(Node $node, string $runtimeUnit): bool;

    public function restart(Node $node, string $runtimeUnit): bool;

    public function logScript(App $app, Process $process, ?Workspace $workspace, string $runtimeUnit, int $lines, bool $follow): string;
}
