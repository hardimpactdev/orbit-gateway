<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Models\App;
use App\Models\AppWebSocketBinding;
use RuntimeException;

final readonly class WebSocketCredentials
{
    /**
     * @param  list<string>  $publicHosts
     * @param  list<string>  $allowedOrigins
     */
    public function __construct(
        public string $app,
        public string $internalHost,
        public array $publicHosts,
        public array $allowedOrigins,
        public string $reverbAppId,
        public string $reverbAppKey,
        public string $reverbAppSecret,
    ) {}

    public static function fromBinding(AppWebSocketBinding $binding): self
    {
        $binding->loadMissing('app');

        if (! $binding->app instanceof App) {
            throw new RuntimeException('WebSocket credentials require an app binding owner.');
        }

        return new self(
            app: $binding->app->name,
            internalHost: WebSocketRouteRegistrar::ServiceDomain,
            publicHosts: self::stringList($binding->public_hosts),
            allowedOrigins: self::stringList($binding->allowed_origins),
            reverbAppId: $binding->reverb_app_id,
            reverbAppKey: $binding->reverb_app_key,
            reverbAppSecret: $binding->reverb_app_secret,
        );
    }

    /**
     * @return array{
     *     app: string,
     *     internal_host: string,
     *     public_hosts: list<string>,
     *     allowed_origins: list<string>,
     *     reverb_app_id: string,
     *     reverb_app_key: string,
     *     reverb_app_secret: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'app' => $this->app,
            'internal_host' => $this->internalHost,
            'public_hosts' => $this->publicHosts,
            'allowed_origins' => $this->allowedOrigins,
            'reverb_app_id' => $this->reverbAppId,
            'reverb_app_key' => $this->reverbAppKey,
            'reverb_app_secret' => $this->reverbAppSecret,
        ];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
