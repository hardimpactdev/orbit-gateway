<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\DriftKind;
use App\Models\AppInstance;
use App\Models\Node;

final readonly class AppRuntimeRequirementProbe
{
    public function __construct(
        private RemoteShell $remoteShell,
        private AppRuntimeContainerRenderer $renderer,
    ) {}

    /**
     * @return list<DriftEntry>
     */
    public function drift(AppInstance $instance): array
    {
        $required = $instance->runtimeRequirements()->normalizedPhpExtensions();

        if ($required === []) {
            return [];
        }

        $instance->loadMissing('app.node');
        $app = $instance->app;

        if ($instance->driver !== AppInstanceDriver::Orbit || $app->runtime_kind !== AppRuntimeKind::Php) {
            return [];
        }

        if (! $app->node instanceof Node) {
            return [
                new DriftEntry(
                    family: 'app',
                    key: 'app.runtime_extensions_unverifiable',
                    kind: DriftKind::Unverifiable,
                    summary: "Required PHP extensions for app '{$app->name}' instance '{$instance->name}' cannot be verified because the app has no owning node.",
                    detail: [
                        'app' => $app->name,
                        'instance' => $instance->name,
                        'required_extensions' => $required,
                    ],
                ),
            ];
        }

        $container = $this->renderer->containerName($app);
        $script = sprintf('docker exec %s php -m', escapeshellarg($container));
        $result = $this->remoteShell->run($app->node, $script);

        if (! $result->successful()) {
            return [
                new DriftEntry(
                    family: 'app',
                    key: 'app.runtime_extensions_unverifiable',
                    kind: DriftKind::Unverifiable,
                    summary: "Required PHP extensions for app '{$app->name}' instance '{$instance->name}' cannot be verified.",
                    detail: [
                        'app' => $app->name,
                        'instance' => $instance->name,
                        'container' => $container,
                        'required_extensions' => $required,
                        'error' => trim($result->stderr) ?: trim($result->stdout),
                    ],
                ),
            ];
        }

        $observed = array_map(
            static fn (string $extension): string => strtolower(trim($extension)),
            array_filter(explode("\n", $result->stdout)),
        );

        $missing = array_values(array_diff($required, $observed));

        if ($missing === []) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'app',
                key: 'app.runtime_extension_missing',
                kind: DriftKind::Divergent,
                summary: "App '{$app->name}' instance '{$instance->name}' is missing required PHP extension(s): ".implode(', ', $missing).'.',
                detail: [
                    'app' => $app->name,
                    'instance' => $instance->name,
                    'container' => $container,
                    'required_extensions' => $required,
                    'observed_extensions' => array_values(array_unique($observed)),
                    'missing_extensions' => $missing,
                ],
            ),
        ];
    }
}
