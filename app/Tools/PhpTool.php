<?php

declare(strict_types=1);

namespace App\Tools;

use App\Services\Php\PhpRuntimeCatalog;

final class PhpTool extends BaseTool
{
    public function slug(): string
    {
        return 'php';
    }

    #[\Override]
    public function category(): string
    {
        return 'runtime';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'remove', 'update'];
    }

    #[\Override]
    public function probeMetadata(): array
    {
        $catalog = new PhpRuntimeCatalog;

        return [
            'probe' => 'docker_images',
            'images' => $catalog->supportedImages(),
        ];
    }
}
