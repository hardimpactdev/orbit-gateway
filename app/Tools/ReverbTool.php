<?php

declare(strict_types=1);

namespace App\Tools;

final class ReverbTool extends DockerComposeTool
{
    public function slug(): string
    {
        return 'reverb';
    }

    #[\Override]
    public function category(): string
    {
        return 'communication';
    }
}
