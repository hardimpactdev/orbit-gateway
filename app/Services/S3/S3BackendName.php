<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Models\Node;

final class S3BackendName
{
    public const int BackendPort = 8333;

    public const string BackendScheme = 'http';

    public function forNode(Node $node): string
    {
        return "{$node->name}.s3.orbit";
    }

    public function backendUrl(Node $node): string
    {
        $backendHost = $this->forNode($node);

        return self::BackendScheme.'://'.$backendHost.':'.self::BackendPort;
    }
}
