<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Nodes\NodeRoleName;
use App\Models\App;
use App\Models\Node;

final readonly class AppDevelopmentPackagesMount
{
    public const string Target = '/packages';

    private const string SafeSourcePattern = '#^/home/(?!(?:\.{1,2})/)(?<user>[A-Za-z0-9._-]+)/packages$#';

    public static function isSafeSource(string $source): bool
    {
        return self::userForSafeSource($source) !== null;
    }

    public static function userForSafeSource(string $source): ?string
    {
        if (preg_match(self::SafeSourcePattern, $source, $matches) !== 1) {
            return null;
        }

        return $matches['user'];
    }

    /**
     * @return array{source: string, target: string, read_only: bool}|null
     */
    public function forApp(App $app): ?array
    {
        $app->loadMissing('node.roleAssignments');

        if (! $app->node instanceof Node) {
            return null;
        }

        if (! $app->node->hasActiveRole(NodeRoleName::AppDevelopment->value)) {
            return null;
        }

        $nodeUser = trim((string) ($app->node->user ?: 'orbit'));

        if ($nodeUser === '') {
            return null;
        }

        return [
            'source' => "/home/{$nodeUser}/packages",
            'target' => self::Target,
            'read_only' => false,
        ];
    }
}
