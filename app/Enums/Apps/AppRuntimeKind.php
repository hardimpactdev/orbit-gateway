<?php

declare(strict_types=1);

namespace App\Enums\Apps;

enum AppRuntimeKind: string
{
    case Php = 'php';
    case Static = 'static';

    public function usesPhpRuntimeContainer(): bool
    {
        return $this === self::Php;
    }
}
