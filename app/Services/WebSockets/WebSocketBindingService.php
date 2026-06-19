<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Models\App;
use App\Models\AppWebSocketBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final readonly class WebSocketBindingService
{
    public function __construct(
        private WebSocketRouteRegistrar $routes,
        private WebSocketRuntimeAppConfigSyncer $runtimeAppConfigSyncer,
    ) {}

    /**
     * @param  array<int, mixed>  $publicHosts
     */
    public function enable(App $app, array $publicHosts): AppWebSocketBinding
    {
        $binding = DB::transaction(function () use ($app, $publicHosts): AppWebSocketBinding {
            $this->routes->syncServiceRoute();

            $binding = $this->existingBinding($app);
            $attributes = [
                'enabled' => true,
                'allowed_origins' => $this->allowedOrigins($app),
                'public_hosts' => $this->normalizePublicHosts($publicHosts),
            ];

            if ($binding instanceof AppWebSocketBinding) {
                $binding->fill($attributes);
                $binding->save();
            } else {
                $binding = AppWebSocketBinding::query()->create([
                    'app_id' => $app->id,
                    'reverb_app_id' => $app->name,
                    'reverb_app_key' => Str::random(32),
                    'reverb_app_secret' => Str::random(48),
                    ...$attributes,
                ]);
            }

            $binding = $binding->refresh();
            $this->routes->syncPublicHosts($binding);

            return $binding->refresh();
        });

        $this->runtimeAppConfigSyncer->sync();

        return $binding->refresh();
    }

    public function credentials(App $app): WebSocketCredentials
    {
        $binding = $this->enabledBinding($app);

        return WebSocketCredentials::fromBinding($binding);
    }

    public function disable(App $app): AppWebSocketBinding
    {
        $binding = DB::transaction(function () use ($app): AppWebSocketBinding {
            $binding = $this->binding($app);

            $binding->fill([
                'enabled' => false,
                'public_hosts' => [],
            ]);
            $binding->save();

            $binding = $binding->refresh();
            $this->routes->syncPublicHosts($binding);

            return $binding->refresh();
        });

        $this->runtimeAppConfigSyncer->sync();

        return $binding->refresh();
    }

    private function existingBinding(App $app): ?AppWebSocketBinding
    {
        $binding = AppWebSocketBinding::query()
            ->where('app_id', $app->id)
            ->first();

        return $binding instanceof AppWebSocketBinding ? $binding : null;
    }

    private function binding(App $app): AppWebSocketBinding
    {
        $binding = $this->existingBinding($app);

        if (! $binding instanceof AppWebSocketBinding) {
            throw new RuntimeException("App '{$app->name}' does not have a websocket binding.");
        }

        return $binding;
    }

    private function enabledBinding(App $app): AppWebSocketBinding
    {
        $binding = $this->binding($app);

        if (! $binding->enabled) {
            throw new RuntimeException("App '{$app->name}' does not have an enabled websocket binding.");
        }

        return $binding;
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(App $app): array
    {
        $domain = is_string($app->domain) ? trim($app->domain) : '';

        if ($domain === '') {
            return [];
        }

        return ["https://{$domain}"];
    }

    /**
     * @param  array<int, mixed>  $publicHosts
     * @return list<string>
     */
    private function normalizePublicHosts(array $publicHosts): array
    {
        $hosts = [];

        foreach ($publicHosts as $publicHost) {
            if (! is_string($publicHost)) {
                throw new InvalidArgumentException('WebSocket public hosts must be strings.');
            }

            $host = Str::lower(trim($publicHost));

            if ($host === '') {
                continue;
            }

            if (str_contains($host, '://')) {
                throw new InvalidArgumentException('WebSocket public hosts must be hostnames, not URLs.');
            }

            if (! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }
}
