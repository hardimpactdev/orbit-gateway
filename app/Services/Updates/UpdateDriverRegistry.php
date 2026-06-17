<?php

declare(strict_types=1);

namespace App\Services\Updates;

final readonly class UpdateDriverRegistry
{
    /**
     * @param  list<UpdateDriver>  $drivers
     */
    public function __construct(private array $drivers) {}

    /**
     * @return list<UpdateDriver>
     */
    public function driversFor(UpdateTarget $target): array
    {
        return array_values(array_filter(
            $this->drivers,
            static fn (UpdateDriver $driver): bool => $driver->supports($target),
        ));
    }
}
