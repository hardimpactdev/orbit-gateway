<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessRestartPolicy: string
{
    case Never = 'never';
    case OnFailure = 'on_failure';
    case Always = 'always';

    public function toDocker(): string
    {
        return match ($this) {
            self::Never => 'no',
            self::OnFailure => 'on-failure',
            self::Always => 'always',
        };
    }

    public function toSystemd(): string
    {
        return match ($this) {
            self::Never => 'no',
            self::OnFailure => 'on-failure',
            self::Always => 'always',
        };
    }
}
