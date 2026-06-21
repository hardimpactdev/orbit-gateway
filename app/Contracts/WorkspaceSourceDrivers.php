<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\App;
use App\Models\Node;

interface WorkspaceSourceDrivers
{
    public function resolve(App $app): WorkspaceSourceDriver;

    public function effectiveAdapter(App $app): ?string;

    /**
     * @return array{label: string, done_label: string}
     */
    public function progressLabels(App $app, Node $node): array;
}
