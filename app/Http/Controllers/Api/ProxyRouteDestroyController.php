<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Proxy\ProxyRouteIntent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProxyRouteDestroyController implements Loggable
{
    private ?Node $activitySubject = null;

    public function __invoke(string $domain, Request $request, ProxyRouteIntent $intent): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if ($request->boolean('destructive_consent') !== true) {
            return $this->error('destructive_consent_required', 'Use --force to remove this proxy route.', ['field' => 'force'], 422);
        }

        try {
            $result = $intent->remove($domain, $caller);
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $this->statusFor($e));
        }

        $this->activitySubject = $caller;

        return response()->json([
            'success' => [
                'data' => $result['data'],
                'meta' => $result['meta'],
            ],
        ]);
    }

    private function statusFor(GatewayApiException $e): int
    {
        return match ($e->errorCode()) {
            'authorization_failed' => 403,
            'proxy.not_found' => 404,
            'proxy.owned_route_denied' => 409,
            default => 422,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Destructive;
    }

    public function type(): string
    {
        return 'api:DELETE /proxy-routes/{domain}';
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return ['domain' => request()->route('domain')];
    }

    public function description(): ?string
    {
        return null;
    }
}
