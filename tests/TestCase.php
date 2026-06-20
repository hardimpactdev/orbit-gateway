<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\RemoteShell;
use App\Services\Workspaces\WorkspaceReadinessProbe;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\NullRemoteShell;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (env('ORBIT_E2E') === '1') {
            return;
        }

        $this->isolateStoragePathPerWorker();

        $this->app->instance(RemoteShell::class, new NullRemoteShell);
        // `maxAttempts: 0` makes `probe()` skip its HTTP loop entirely and
        // return the initial `not_run` snapshot. Setup-related tests still
        // exercise the rest of the workflow; tests that exercise the probe
        // itself instantiate it directly with their own attempt count.
        $this->app->instance(
            WorkspaceReadinessProbe::class,
            new WorkspaceReadinessProbe(maxAttempts: 0, retryDelayMilliseconds: 0),
        );
    }

    /**
     * Give each ParaTest worker its own `storage/` directory so tests that
     * write to `storage_path(...)` cannot race when running `pest --parallel`.
     */
    private function isolateStoragePathPerWorker(): void
    {
        $token = getenv('TEST_TOKEN');
        if ($token === false || $token === '') {
            return;
        }

        $workerStorage = $this->app->basePath('storage/framework/testing/worker-'.$token);
        @mkdir($workerStorage.'/app', recursive: true);
        @mkdir($workerStorage.'/framework/cache', recursive: true);
        @mkdir($workerStorage.'/framework/sessions', recursive: true);
        @mkdir($workerStorage.'/framework/testing', recursive: true);
        @mkdir($workerStorage.'/framework/views', recursive: true);
        @mkdir($workerStorage.'/logs', recursive: true);

        $this->app->useStoragePath($workerStorage);
    }
}
