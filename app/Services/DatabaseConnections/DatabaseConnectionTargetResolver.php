<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;

final class DatabaseConnectionTargetResolver
{
    public function resolveNode(?string $selector): ?Node
    {
        if ($selector === null || trim($selector) === '') {
            return null;
        }

        return Node::query()
            ->where('name', trim($selector))
            ->first();
    }

    public function resolveApp(?string $selector): ?App
    {
        if ($selector === null || trim($selector) === '') {
            return null;
        }

        $selector = trim($selector);

        return App::query()
            ->where('name', $selector)
            ->orWhere('domain', $selector)
            ->first();
    }

    public function resolveWorkspace(?string $selector): ?Workspace
    {
        if ($selector === null || trim($selector) === '') {
            return null;
        }

        return Workspace::query()
            ->where('name', trim($selector))
            ->first();
    }

    public function validEnvPrefix(?string $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        return preg_match('/^[A-Z][A-Z0-9_]*$/', $value) === 1;
    }
}
