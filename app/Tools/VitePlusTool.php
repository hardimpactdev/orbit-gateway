<?php

declare(strict_types=1);

namespace App\Tools;

final class VitePlusTool extends BaseTool
{
    public function slug(): string
    {
        return 'viteplus';
    }

    #[\Override]
    public function category(): string
    {
        return 'always';
    }
}
