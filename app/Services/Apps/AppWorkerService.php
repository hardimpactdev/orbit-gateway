<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Apps\AppWorkerReadinessResult;
use App\Data\Apps\PhpWorkerConfig;
use App\Models\App;

final readonly class AppWorkerService
{
    public function __construct(
        private AppWorkerReadiness $readiness,
    ) {}

    /**
     * @return array{
     *     ready: bool,
     *     app: App,
     *     readiness: AppWorkerReadinessResult,
     *     changed: bool,
     * }
     */
    public function enable(App $app): array
    {
        $readiness = $this->readiness->assess($app);

        if (! $readiness->ready) {
            return [
                'ready' => false,
                'app' => $app,
                'readiness' => $readiness,
                'changed' => false,
            ];
        }

        $changed = ! $app->worker_enabled || ! is_array($app->worker_config);
        $existing = is_array($app->worker_config) ? $app->worker_config : [];
        $config = PhpWorkerConfig::fromArray($existing)->toArray();

        $app->worker_enabled = true;
        $app->worker_config = $config;
        $app->save();

        return [
            'ready' => true,
            'app' => $app,
            'readiness' => $readiness,
            'changed' => $changed || $existing !== $config,
        ];
    }

    /**
     * @return array{app: App, changed: bool}
     */
    public function disable(App $app): array
    {
        $changed = $app->worker_enabled === true;

        $app->worker_enabled = false;
        // Keep worker_config so subsequent enables remember the prior config.
        $app->save();

        return [
            'app' => $app,
            'changed' => $changed,
        ];
    }
}
