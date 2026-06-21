<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Services\Apps\AppRuntimeContainerApplyException;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\AppRuntimeImageUnavailableException;
use App\Services\Apps\AppRuntimeUserUnavailableException;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use RuntimeException;
use Throwable;

final readonly class EnactAppRuntime
{
    public function __construct(
        private EnsureAppProxyRoute $ensureAppProxyRoute,
        private EnsureAppProcessRuntimeUnits $ensureAppProcessRuntimeUnits,
        private AppRuntimeContainerRenderer $appRuntimeContainerRenderer,
        private AppRuntimeContainerManager $appRuntimeContainerManager,
        private EnsureFrankenPhpRuntimeProcess $ensureFrankenPhpRuntimeProcess,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(App $app): array
    {
        $app->loadMissing('node');

        if ($app->node === null) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $warnings = [];

        if ($app->runtime_kind === AppRuntimeKind::Php) {
            try {
                $this->ensureFrankenPhpRuntimeProcess->forApp($app);
                $container = $this->appRuntimeContainerRenderer->render($app);
                $this->appRuntimeContainerManager->apply($app->node, $container);
            } catch (AppRuntimeImageUnavailableException $exception) {
                $warnings[] = $this->phpVersionUnavailableWarning($app, $exception);
            } catch (AppRuntimeUserUnavailableException $exception) {
                $warnings[] = $this->runtimeUserUnavailableWarning($app, $exception);
            } catch (AppRuntimeContainerApplyException $exception) {
                $warnings[] = $this->runtimeContainerWarning($app, $exception->hadExistingContainer, $exception);
            } catch (Throwable $exception) {
                $warnings[] = $this->runtimeContainerWarning($app, hadExistingContainer: false, exception: $exception);
            }
        }

        // Always record gateway-side intent for app-owned proxy routes and
        // process units, even when the runtime container apply hit a
        // retryable warning (image-unavailable, container apply failure).
        // Without this, the warning tells the user to run
        // `doctor --family=app --restore` but app doctor cannot create
        // a missing proxy route — proxy/process is the gateway's
        // responsibility, not app doctor's. Static apps skip the runtime
        // container apply path entirely; their file_server-only route is
        // still recorded here.
        return [
            ...$warnings,
            ...$this->ensureAppProxyRoute->handle($app),
            ...$this->ensureAppProcessRuntimeUnits->handle($app),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function runtimeContainerWarning(App $app, bool $hadExistingContainer, Throwable $exception): array
    {
        $code = $hadExistingContainer
            ? 'app.runtime_container_mismatch'
            : 'app.runtime_container_missing';

        $action = $hadExistingContainer ? 'recreated' : 'installed';

        return [
            'code' => $code,
            'family' => 'app',
            'message' => "FrankenPHP runtime container for '{$app->name}' could not be {$action} on '{$app->node->name}': {$exception->getMessage()}",
            'next_command' => 'doctor --family=app --restore',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function phpVersionUnavailableWarning(App $app, AppRuntimeImageUnavailableException $exception): array
    {
        return [
            'code' => 'app.php_version_unavailable',
            'family' => 'app',
            'message' => "PHP {$app->php_version} runtime image '{$exception->image}' is not available on node '{$app->node->name}'. Make the image available, then run doctor.",
            'next_command' => 'doctor --family=app --restore',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function runtimeUserUnavailableWarning(App $app, AppRuntimeUserUnavailableException $exception): array
    {
        return [
            'code' => 'app.security.system_user',
            'family' => 'app',
            'message' => "Production runtime user '{$exception->runtimeUser}' for app '{$app->name}' is missing on '{$app->node->name}': {$exception->getMessage()}",
            'next_command' => 'doctor --family=app --restore',
        ];
    }
}
