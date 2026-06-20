<?php

declare(strict_types=1);

namespace App\Data\Operations;

/**
 * Result of the `update:all` fleet version probe.
 *
 * Records the target release version, the gateway's currently baked version,
 * and each selected workload node's probed version (`null` when it could not be
 * read). `outdatedCount` is the number of installations — gateway plus workload
 * nodes — whose current version is behind, unknown, or otherwise not equal to
 * the target. An unreadable version counts as outdated so the node is still
 * updated rather than silently skipped.
 */
final readonly class FleetVersionReport
{
    /**
     * @param  array<string, string|null>  $nodeVersions  Keyed by node name.
     */
    public function __construct(
        public string $targetVersion,
        public ?string $gatewayVersion,
        public array $nodeVersions,
        public int $outdatedCount,
    ) {}

    public function allCurrent(): bool
    {
        return $this->outdatedCount === 0;
    }

    /**
     * Stable human-progress row targets for an outdated fleet check: gateway,
     * local, then selected workload node names in selector order.
     *
     * @return list<string>
     */
    public function progressUpdateTargets(): array
    {
        return array_merge(
            ['gateway', 'local'],
            array_keys($this->nodeVersions),
        );
    }
}
