<?php

declare(strict_types=1);

namespace App\Data\Apps;

use Spatie\LaravelData\Data;

final class AppInstanceRuntimeRequirementsData extends Data
{
    /**
     * @param  list<string>  $php_extensions
     */
    public function __construct(
        public array $php_extensions = [],
    ) {}

    /**
     * @return list<string>
     */
    public function normalizedPhpExtensions(): array
    {
        $extensions = array_map(
            static fn (string $extension): string => strtolower(trim($extension)),
            array_filter($this->php_extensions, is_string(...)),
        );

        $extensions = array_values(array_unique(array_filter($extensions)));
        sort($extensions);

        return $extensions;
    }
}
