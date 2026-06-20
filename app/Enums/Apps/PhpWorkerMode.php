<?php

declare(strict_types=1);

namespace App\Enums\Apps;

enum PhpWorkerMode: string
{
    case Classic = 'classic';
    case Worker = 'worker';

    public function isWorker(): bool
    {
        return $this === self::Worker;
    }

    public static function fromBool(bool $enabled): self
    {
        return $enabled ? self::Worker : self::Classic;
    }
}
