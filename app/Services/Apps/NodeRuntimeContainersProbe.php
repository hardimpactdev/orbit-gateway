<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Apps\NodeRuntimeContainersProbeStatus;

final readonly class NodeRuntimeContainersProbe
{
    public function __construct(
        public NodeRuntimeContainersProbeStatus $status,
        public ProbeSnapshot $containers,
        public string $error = '',
    ) {}
}
