<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\App;
use App\Models\AppAnalyticsBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final readonly class AppAnalyticsBindingService
{
    public function __construct(
        private AnalyticsRouteRegistrar $routes,
    ) {}

    /**
     * @param  array<int, mixed>  $publicHosts
     */
    public function enable(App $app, array $publicHosts): AppAnalyticsBinding
    {
        return DB::transaction(function () use ($app, $publicHosts): AppAnalyticsBinding {
            $this->routes->syncServiceRoute();

            $binding = $this->existingBinding($app);
            $attributes = [
                'enabled' => true,
                'public_hosts' => $this->normalizePublicHosts($app, $publicHosts),
            ];

            if ($binding instanceof AppAnalyticsBinding) {
                $binding->fill($attributes);
                $binding->save();
            } else {
                $binding = AppAnalyticsBinding::query()->create([
                    'app_id' => $app->id,
                    ...$attributes,
                ]);
            }

            $binding = $binding->refresh();
            $this->routes->syncPublicHosts($binding);

            return $binding->refresh();
        });
    }

    public function disable(App $app): AppAnalyticsBinding
    {
        return DB::transaction(function () use ($app): AppAnalyticsBinding {
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
    }

    public function show(App $app): AppAnalyticsBinding
    {
        return $this->binding($app);
    }

    private function existingBinding(App $app): ?AppAnalyticsBinding
    {
        $binding = AppAnalyticsBinding::query()
            ->where('app_id', $app->id)
            ->first();

        return $binding instanceof AppAnalyticsBinding ? $binding : null;
    }

    private function binding(App $app): AppAnalyticsBinding
    {
        $binding = $this->existingBinding($app);

        if (! $binding instanceof AppAnalyticsBinding) {
            throw new RuntimeException("App '{$app->name}' does not have an analytics binding.");
        }

        return $binding;
    }

    /**
     * @param  array<int, mixed>  $publicHosts
     * @return list<string>
     */
    private function normalizePublicHosts(App $app, array $publicHosts): array
    {
        $hosts = [];

        foreach ($publicHosts as $publicHost) {
            if (! is_string($publicHost)) {
                throw new InvalidArgumentException('Analytics public hosts must be strings.');
            }

            $host = Str::lower(trim($publicHost));

            if ($host === '') {
                continue;
            }

            if (str_contains($host, '://')) {
                throw new InvalidArgumentException('Analytics public hosts must be hostnames, not URLs.');
            }

            if (! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        if ($hosts !== []) {
            return $hosts;
        }

        $domain = is_string($app->domain) ? trim($app->domain) : '';

        if ($domain === '') {
            return [];
        }

        return ["analytics.{$domain}"];
    }
}
