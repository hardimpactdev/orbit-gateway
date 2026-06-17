<?php

declare(strict_types=1);

namespace App\Services\Updates;

interface UpdateDriver
{
    public function key(): string;

    /**
     * @return list<UpdateDriverTarget>
     */
    public function supportedTargets(): array;

    public function supports(UpdateTarget $target): bool;

    public function probe(UpdateTarget $target): UpdatePostureSnapshot;

    public function apply(UpdateTarget $target): UpdateApplyResult;
}
