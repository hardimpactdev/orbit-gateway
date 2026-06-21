<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Models\NodeTool;

/**
 * Result of S3ServiceConfigurator::configure().
 *
 * Carries the resolved service config, the rendered runtime container
 * spec, and the persisted seaweedfs NodeTool row so that convergence and
 * routing tasks can consume them without re-resolving.
 */
final readonly class S3ServiceConfiguratorResult
{
    public function __construct(
        public S3ServiceConfig $serviceConfig,
        public S3RuntimeContainer $runtimeContainer,
        public NodeTool $seaweedfsTool,
    ) {}
}
